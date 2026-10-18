using System.Reflection;

namespace CentreCivic.PrintAgent;

public enum AgentState
{
    NotConfigured,
    Connecting,
    Online,
    Offline,
    BadKey,
}

public sealed record JobResult(DateTime At, string Printer, string Title, bool Printed, string? Error);

/// <summary>
/// The heart of the program: checks in with the server, keeps the realtime connection,
/// and prints every job of its printers as soon as it arrives (woken by the websocket, and
/// pulling every few seconds anyway). Each job is acknowledged as printed or failed.
/// </summary>
public sealed class AgentRunner : IDisposable
{
    private readonly IPrintBackend backend;
    private readonly SemaphoreSlim wake = new(0, 1);
    private readonly RealtimeSocket realtime;
    private readonly object gate = new();
    private ServerApi? api;
    private AgentConfig config;
    private DateTime lastHello = DateTime.MinValue;
    private DateTime lastPrinterList = DateTime.MinValue;

    public AgentRunner(AgentConfig config, IPrintBackend backend)
    {
        this.config = config;
        this.backend = backend;
        realtime = new RealtimeSocket(Wake);
        realtime.ConnectedChanged += _ => StatusChanged?.Invoke();
    }

    public static string Version { get; } = (Assembly.GetEntryAssembly()?.GetCustomAttribute<AssemblyInformationalVersionAttribute>()?.InformationalVersion ?? "1.0.0").Split('+')[0];

    public AgentState State { get; private set; } = AgentState.NotConfigured;

    public string? Message { get; private set; }

    public string? AgentName { get; private set; }

    public bool Realtime => realtime.Connected;

    public IReadOnlyList<PrinterConfig> Printers { get; private set; } = [];

    /// <summary>The latest program published on the server.</summary>
    public string? LatestVersion { get; private set; }

    /// <summary>When the server last told which version is the latest.</summary>
    public DateTime? CheckedAt { get; private set; }

    /// <summary>The configuration in use (the window edits it through this runner).</summary>
    public AgentConfig Config
    {
        get
        {
            lock (gate)
            {
                return config;
            }
        }
    }

    public event Action? StatusChanged;

    /// <summary>The local printer list changed from here (taken over from the server).</summary>
    public event Action? PrintersChanged;

    public event Action<JobResult>? JobFinished;

    public void Wake()
    {
        try
        {
            wake.Release();
        }
        catch (SemaphoreFullException)
        {
            // Already awake.
        }
    }

    /// <summary>New key or server: reconnect from scratch.</summary>
    public void Reconfigure(AgentConfig next)
    {
        lock (gate)
        {
            config = next;
        }

        api?.Dispose();
        api = null;
        lastHello = DateTime.MinValue;
        realtime.Stop();
        Wake();
    }

    /// <summary>Save the printers set up in the window and send them to the server now.</summary>
    public void SetPrinters(List<LocalPrinter> printers)
    {
        lock (gate)
        {
            config.Printers = printers;
            config.Save();
        }

        Log.Write($"Impressores: {string.Join(", ", printers.Select(p => $"«{p.Name}» ({p.Where})"))}.");
        CheckNow();
    }

    public void SetAutoUpdate(bool enabled)
    {
        lock (gate)
        {
            config.AutoUpdate = enabled;
            config.Save();
        }
    }

    /// <summary>Say hello right away: sends the printers and asks for the latest version.</summary>
    public void CheckNow()
    {
        lastHello = DateTime.MinValue;
        Wake();
    }

    public async Task RunAsync(CancellationToken ct)
    {
        var backoff = TimeSpan.FromSeconds(2);

        while (!ct.IsCancellationRequested)
        {
            TimeSpan wait;

            try
            {
                if (!config.IsComplete)
                {
                    SetState(AgentState.NotConfigured, "Enganxa la clau de connexió de Gestió → Impressió.");
                    await WaitAsync(TimeSpan.FromMinutes(10), ct);
                    continue;
                }

                api ??= new ServerApi(config.ServerUrl, config.Token, Version);

                if (State is AgentState.NotConfigured or AgentState.BadKey)
                {
                    SetState(AgentState.Connecting, "Connectant…");
                }

                if (DateTime.UtcNow - lastHello > TimeSpan.FromMinutes(5))
                {
                    await HelloAsync(ct);
                }

                var jobs = await api.JobsAsync(new { machine = Environment.MachineName, version = Version }, ct);
                SetState(AgentState.Online, null);
                backoff = TimeSpan.FromSeconds(2);

                foreach (var job in jobs)
                {
                    await PrintAsync(job, ct);
                }

                // More may be waiting (20 per pull); otherwise sleep until woken.
                wait = jobs.Count >= 20 ? TimeSpan.Zero : realtime.Connected ? TimeSpan.FromSeconds(20) : TimeSpan.FromSeconds(3);
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested)
            {
                break;
            }
            catch (BadKeyException e)
            {
                realtime.Stop();
                lastHello = DateTime.MinValue;
                SetState(AgentState.BadKey, e.Message);
                wait = TimeSpan.FromMinutes(1);
            }
            catch (Exception e)
            {
                SetState(AgentState.Offline, "Sense connexió amb el servidor: " + e.Message + " Es torna a provar sol.");
                wait = backoff;
                backoff = TimeSpan.FromSeconds(Math.Min(30, backoff.TotalSeconds * 2));
            }

            if (wait > TimeSpan.Zero)
            {
                await WaitAsync(wait, ct);
            }
        }

        realtime.Stop();
    }

    private async Task HelloAsync(CancellationToken ct)
    {
        var printers = DateTime.UtcNow - lastPrinterList > TimeSpan.FromMinutes(5) || lastHello == DateTime.MinValue ? backend.InstalledPrinters() : null;
        List<LocalPrinter>? definitions;

        lock (gate)
        {
            definitions = config.PrintersAdopted ? [.. config.Printers] : null;
        }

        var hello = await api!.HelloAsync(new { machine = Environment.MachineName, version = Version, printers, definitions }, ct);

        if (printers is not null)
        {
            lastPrinterList = DateTime.UtcNow;
        }

        lastHello = DateTime.UtcNow;
        AgentName = hello.Agent.Name;
        Printers = hello.Printers;
        LatestVersion = hello.LatestVersion;
        CheckedAt = DateTime.Now;
        realtime.Configure(hello.Realtime);

        if (definitions is null)
        {
            Adopt(hello.Printers);
        }

        StatusChanged?.Invoke();
    }

    /// <summary>
    /// First hello of this version: the printers set up on the web for this computer become
    /// the program's own, and from then on the program's list is the one that counts.
    /// </summary>
    private void Adopt(List<PrinterConfig> server)
    {
        // An older server does not know about printers set up here yet.
        if (server.Any(p => p.Key is null))
        {
            return;
        }

        lock (gate)
        {
            var list = config.Printers.ToList();
            list.AddRange(server.Where(p => list.All(l => l.Key != p.Key)).Select(LocalPrinter.From));
            config.Printers = list;
            config.PrintersAdopted = true;
            config.Save();
        }

        Log.Write($"Impressores de la web incorporades al programa: {server.Count}.");
        lastHello = DateTime.MinValue; // send the list back right away
        Wake();
        PrintersChanged?.Invoke();
    }

    private async Task PrintAsync(PrintJob job, CancellationToken ct)
    {
        string? error = null;

        try
        {
            // The driver and spooler calls block: keep them off the loop.
            await Task.Run(() => backend.Print(job), ct);
            Log.Write($"Imprès a «{job.Printer.Name}» ({job.Printer.Where}): {job.Title}");
        }
        catch (Exception e) when (e is not OperationCanceledException)
        {
            error = e.Message;
            Log.Write($"ERROR a «{job.Printer.Name}» ({job.Printer.Where}): {job.Title}: {e.Message}");
        }

        JobFinished?.Invoke(new JobResult(DateTime.Now, job.Printer.Name, job.Title, error is null, error));

        // A job never acknowledged is handed out again after two minutes (better twice than
        // never), so try hard to acknowledge it.
        for (var attempt = 1; attempt <= 5; attempt++)
        {
            try
            {
                await api!.AckAsync(job.Uuid, error is null, error, ct);
                return;
            }
            catch (Exception e) when (e is not OperationCanceledException and not BadKeyException)
            {
                Log.Write($"No s'ha pogut confirmar la impressió al servidor (intent {attempt}): {e.Message}");
                await Task.Delay(TimeSpan.FromSeconds(attempt * 2), ct);
            }
        }
    }

    private async Task WaitAsync(TimeSpan time, CancellationToken ct)
    {
        try
        {
            await wake.WaitAsync(time, ct);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
        }
    }

    private void SetState(AgentState state, string? message)
    {
        if (State == state && Message == message)
        {
            return;
        }

        if (State != state)
        {
            Log.Write($"Estat: {state}{(message is null ? "" : " — " + message)}");
        }

        State = state;
        Message = message;
        StatusChanged?.Invoke();
    }

    /// <summary>Download the latest program from the server (automatic update).</summary>
    public Task DownloadLatestAsync(string path, CancellationToken ct) => (api ?? throw new InvalidOperationException("Sense connexió.")).DownloadAsync(path, ct);

    public void Dispose()
    {
        realtime.Dispose();
        api?.Dispose();
    }
}
