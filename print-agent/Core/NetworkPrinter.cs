using System.Net.Sockets;

namespace CentreCivic.PrintAgent;

/// <summary>
/// Sends ESC/POS bytes to a printer on the bar's network (usually port 9100). Works because
/// this computer is on the same network; the server in Germany could never reach it.
/// </summary>
public static class NetworkPrinter
{
    public static void Send(string ip, int port, byte[] bytes)
    {
        using var client = new TcpClient();

        if (!client.ConnectAsync(ip, port).Wait(TimeSpan.FromSeconds(5)) || !client.Connected)
        {
            throw new IOException($"No es pot connectar amb la impressora {ip}:{port}. Comprova que està encesa i que la IP és correcta.");
        }

        client.SendTimeout = 10_000;
        using var stream = client.GetStream();
        stream.Write(bytes);
        stream.Flush();
    }
}
