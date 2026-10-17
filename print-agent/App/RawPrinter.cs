using System.ComponentModel;
using System.Runtime.InteropServices;

namespace CentreCivic.PrintAgent.App;

/// <summary>
/// Sends bytes untouched through the Windows print queue (RAW): the ESC/POS of thermal
/// printers connected by USB, with cut, accents and QR exactly as the server encoded them.
/// </summary>
internal static class RawPrinter
{
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private sealed class DocInfo
    {
        [MarshalAs(UnmanagedType.LPWStr)] public string DocName = "";
        [MarshalAs(UnmanagedType.LPWStr)] public string? OutputFile;
        [MarshalAs(UnmanagedType.LPWStr)] public string DataType = "RAW";
    }

    [DllImport("winspool.drv", EntryPoint = "OpenPrinterW", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern bool OpenPrinter(string printerName, out IntPtr printer, IntPtr defaults);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool ClosePrinter(IntPtr printer);

    [DllImport("winspool.drv", EntryPoint = "StartDocPrinterW", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern int StartDocPrinter(IntPtr printer, int level, [In, MarshalAs(UnmanagedType.LPStruct)] DocInfo info);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool EndDocPrinter(IntPtr printer);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool StartPagePrinter(IntPtr printer);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool EndPagePrinter(IntPtr printer);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool WritePrinter(IntPtr printer, byte[] bytes, int count, out int written);

    private const int ErrorInvalidDatatype = 1804;

    public static void Send(string printerName, byte[] bytes, string title)
    {
        if (!OpenPrinter(printerName, out var printer, IntPtr.Zero))
        {
            throw new IOException($"No es pot obrir la impressora «{printerName}»: {new Win32Exception(Marshal.GetLastWin32Error()).Message}");
        }

        try
        {
            if (StartDocPrinter(printer, 1, new DocInfo { DocName = title }) == 0)
            {
                var error = Marshal.GetLastWin32Error();

                throw new IOException(error == ErrorInvalidDatatype
                    ? $"La impressora «{printerName}» no accepta ESC/POS directe (el seu driver és de tipus XPS). A Gestió → Impressió posa-li el format «Driver de Windows», o instal·la-hi el driver del fabricant o el «Generic / Text Only»."
                    : $"La impressora «{printerName}» no accepta el document: {new Win32Exception(error).Message}");
            }

            try
            {
                StartPagePrinter(printer);

                if (!WritePrinter(printer, bytes, bytes.Length, out var written) || written != bytes.Length)
                {
                    throw new IOException($"No s'ha pogut enviar el tiquet a «{printerName}»: {new Win32Exception(Marshal.GetLastWin32Error()).Message}");
                }

                EndPagePrinter(printer);
            }
            finally
            {
                EndDocPrinter(printer);
            }
        }
        finally
        {
            ClosePrinter(printer);
        }
    }
}
