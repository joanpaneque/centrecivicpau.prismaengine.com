using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;

namespace CentreCivic.PrintAgent;

/// <summary>The key was rejected: it was regenerated or the program was removed in Gestió.</summary>
public sealed class BadKeyException(string message) : Exception(message);

/// <summary>
/// The calls to the server. All of them go out from the bar to the server over HTTPS,
/// so nothing has to be opened in the router.
/// </summary>
public sealed class ServerApi : IDisposable
{
    private readonly HttpClient http;

    public ServerApi(string serverUrl, string token, string version)
    {
        http = new HttpClient { BaseAddress = new Uri(serverUrl.TrimEnd('/') + "/"), Timeout = TimeSpan.FromSeconds(30) };
        http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", token);
        http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        http.DefaultRequestHeaders.UserAgent.Add(new ProductInfoHeaderValue("CentreCivicImpressio", version));
    }

    public Task<HelloResponse> HelloAsync(object body, CancellationToken ct) => PostAsync<HelloResponse>("api/print-agent/hello", body, ct);

    public async Task<List<PrintJob>> JobsAsync(object body, CancellationToken ct) => (await PostAsync<JobsResponse>("api/print-agent/jobs", body, ct)).Jobs;

    public async Task AckAsync(string uuid, bool printed, string? error, CancellationToken ct)
    {
        using var response = await http.PostAsJsonAsync($"api/print-agent/jobs/{uuid}/ack", new { status = printed ? "printed" : "failed", error = Trim(error) }, JsonDefaults.Options, ct);

        // 409: the job was meanwhile handed to another program or expired; nothing to do.
        if (response.StatusCode != HttpStatusCode.Conflict)
        {
            await Check(response, ct);
        }
    }

    public async Task DownloadAsync(string path, CancellationToken ct)
    {
        using var response = await http.GetAsync("api/print-agent/download", HttpCompletionOption.ResponseHeadersRead, ct);
        await Check(response, ct);
        await using var file = File.Create(path);
        await response.Content.CopyToAsync(file, ct);
    }

    private async Task<T> PostAsync<T>(string url, object body, CancellationToken ct)
    {
        using var response = await http.PostAsJsonAsync(url, body, JsonDefaults.Options, ct);
        await Check(response, ct);

        return await response.Content.ReadFromJsonAsync<T>(JsonDefaults.Options, ct) ?? throw new InvalidDataException("Resposta buida del servidor.");
    }

    private static async Task Check(HttpResponseMessage response, CancellationToken ct)
    {
        if (response.StatusCode == HttpStatusCode.Unauthorized)
        {
            throw new BadKeyException("La clau no és vàlida (s'ha canviat o s'ha esborrat el programa a Gestió). Enganxa'n una de nova.");
        }

        if (!response.IsSuccessStatusCode)
        {
            var text = await response.Content.ReadAsStringAsync(ct);
            throw new HttpRequestException($"El servidor ha respost {(int)response.StatusCode}: {Trim(text, 160)}");
        }
    }

    private static string? Trim(string? text, int max = 240) => text is null ? null : text.Length <= max ? text : text[..max];

    public void Dispose() => http.Dispose();
}
