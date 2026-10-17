using System.Net.WebSockets;
using System.Text;
using System.Text.Json;

namespace CentreCivic.PrintAgent;

/// <summary>
/// Listens on the program's channel of the server's websocket (Reverb, Pusher protocol) and
/// calls <see cref="Wake"/> the moment there is something to print. If it drops, it
/// reconnects on its own; meanwhile the runner keeps pulling every few seconds.
/// </summary>
public sealed class RealtimeSocket : IDisposable
{
    private readonly Action wake;
    private CancellationTokenSource? loop;
    private RealtimeInfo? info;

    public RealtimeSocket(Action wake) => this.wake = wake;

    public bool Connected { get; private set; }

    public event Action<bool>? ConnectedChanged;

    /// <summary>Start (or restart, if the address changed) listening.</summary>
    public void Configure(RealtimeInfo? next)
    {
        if (next == info && loop is not null)
        {
            return;
        }

        Stop();
        info = next;

        if (next is null || next.Key == "")
        {
            return;
        }

        loop = new CancellationTokenSource();
        _ = RunAsync(next, loop.Token);
    }

    public void Stop()
    {
        loop?.Cancel();
        loop = null;
        SetConnected(false);
    }

    private async Task RunAsync(RealtimeInfo target, CancellationToken ct)
    {
        var delay = TimeSpan.FromSeconds(1);

        while (!ct.IsCancellationRequested)
        {
            try
            {
                await ListenAsync(target, ct);
                delay = TimeSpan.FromSeconds(1);
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested)
            {
                break;
            }
            catch (Exception e)
            {
                Log.Write("Connexió en temps real tallada: " + e.Message);
            }

            SetConnected(false);

            try
            {
                await Task.Delay(delay, ct);
            }
            catch (OperationCanceledException)
            {
                break;
            }

            delay = TimeSpan.FromSeconds(Math.Min(30, delay.TotalSeconds * 2));
        }
    }

    private async Task ListenAsync(RealtimeInfo target, CancellationToken ct)
    {
        using var socket = new ClientWebSocket();
        socket.Options.KeepAliveInterval = TimeSpan.FromSeconds(20);
        var scheme = target.Scheme == "https" ? "wss" : "ws";
        var uri = new Uri($"{scheme}://{target.Host}:{target.Port}/app/{target.Key}?protocol=7&client=centre-civic-impressio&version=1.0&flash=false");

        using var connectTimeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
        connectTimeout.CancelAfter(TimeSpan.FromSeconds(15));
        await socket.ConnectAsync(uri, connectTimeout.Token);

        var lastActivity = DateTime.UtcNow;
        using var pinger = CancellationTokenSource.CreateLinkedTokenSource(ct);
        var pings = Task.Run(async () =>
        {
            while (!pinger.IsCancellationRequested)
            {
                await Task.Delay(TimeSpan.FromSeconds(25), pinger.Token);

                if (DateTime.UtcNow - lastActivity > TimeSpan.FromSeconds(20))
                {
                    await SendAsync(socket, new { @event = "pusher:ping", data = new { } }, pinger.Token);
                }
            }
        }, pinger.Token);

        try
        {
            var buffer = new byte[16 * 1024];

            while (!ct.IsCancellationRequested && socket.State == WebSocketState.Open)
            {
                var message = await ReceiveAsync(socket, buffer, ct);

                if (message is null)
                {
                    break;
                }

                lastActivity = DateTime.UtcNow;
                using var json = JsonDocument.Parse(message);
                var name = json.RootElement.TryGetProperty("event", out var e) ? e.GetString() : null;

                switch (name)
                {
                    case "pusher:connection_established":
                        await SendAsync(socket, new { @event = "pusher:subscribe", data = new { auth = "", channel = target.Channel } }, ct);
                        break;
                    case "pusher_internal:subscription_succeeded":
                        SetConnected(true);
                        Log.Write("Connectat en temps real.");
                        wake(); // catch up with anything sent while disconnected
                        break;
                    case "pusher:ping":
                        await SendAsync(socket, new { @event = "pusher:pong", data = new { } }, ct);
                        break;
                    case "pusher:error":
                        Log.Write("El servidor de temps real diu: " + message);
                        break;
                    default:
                        if (name == target.Event)
                        {
                            wake();
                        }

                        break;
                }
            }
        }
        finally
        {
            pinger.Cancel();

            try
            {
                await pings;
            }
            catch
            {
                // Stopped.
            }
        }
    }

    private static async Task<string?> ReceiveAsync(ClientWebSocket socket, byte[] buffer, CancellationToken ct)
    {
        using var stream = new MemoryStream();

        while (true)
        {
            var result = await socket.ReceiveAsync(buffer, ct);

            if (result.MessageType == WebSocketMessageType.Close)
            {
                return null;
            }

            stream.Write(buffer, 0, result.Count);

            if (result.EndOfMessage)
            {
                return Encoding.UTF8.GetString(stream.ToArray());
            }
        }
    }

    private static readonly SemaphoreSlim SendGate = new(1, 1);

    private static async Task SendAsync(ClientWebSocket socket, object payload, CancellationToken ct)
    {
        var bytes = JsonSerializer.SerializeToUtf8Bytes(payload);
        await SendGate.WaitAsync(ct);

        try
        {
            await socket.SendAsync(bytes, WebSocketMessageType.Text, true, ct);
        }
        finally
        {
            SendGate.Release();
        }
    }

    private void SetConnected(bool value)
    {
        if (Connected != value)
        {
            Connected = value;
            ConnectedChanged?.Invoke(value);
        }
    }

    public void Dispose() => Stop();
}
