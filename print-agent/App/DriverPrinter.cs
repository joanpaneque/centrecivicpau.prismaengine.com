using System.Drawing.Printing;

namespace CentreCivic.PrintAgent.App;

/// <summary>
/// Prints a ticket through the Windows driver, drawing it: for printers that do not speak
/// ESC/POS (or whose driver does not let raw bytes through). Monospaced text sized so the
/// columns of the TPV fit the paper, the QR drawn from its modules and the logo.
/// </summary>
internal static class DriverPrinter
{
    public static void Print(string printerName, DriverDocument document, string title)
    {
        using var print = new PrintDocument();
        print.PrinterSettings.PrinterName = printerName;

        if (!print.PrinterSettings.IsValid)
        {
            throw new IOException($"La impressora «{printerName}» no està instal·lada en aquest ordinador.");
        }

        print.DocumentName = title;
        print.PrintController = new StandardPrintController(); // no "printing page 1" window
        print.OriginAtMargins = false;

        var renderer = new Renderer(document);
        print.PrintPage += renderer.PrintPage;
        print.Print();
    }

    private sealed class Renderer(DriverDocument document)
    {
        private int next;

        public void PrintPage(object? sender, PrintPageEventArgs e)
        {
            var g = e.Graphics!;
            var area = e.PageSettings.PrintableArea;
            var columns = Math.Max(16, document.Width);

            // Font size so that `columns` characters fill the printable width (max 12 pt).
            using var probe = new Font("Consolas", 10f);
            var charWidth = g.MeasureString(new string('M', 20), probe, PointF.Empty, StringFormat.GenericTypographic).Width / 20;
            var size = Math.Clamp(10f * (area.Width / columns) / charWidth, 5f, 12f);
            using var normal = new Font("Consolas", size);
            using var bold = new Font("Consolas", size, FontStyle.Bold);
            using var bigNormal = new Font("Consolas", size * 2);
            using var bigBold = new Font("Consolas", size * 2, FontStyle.Bold);

            var blockWidth = Math.Min(area.Width, g.MeasureString(new string('M', columns), normal, PointF.Empty, StringFormat.GenericTypographic).Width + 2);
            var left = (area.Width - blockWidth) / 2;
            var y = 0f;
            var lineHeight = normal.GetHeight(g);

            while (next < document.Lines.Count)
            {
                var line = document.Lines[next];
                var big = line.Size > 1;
                var font = big ? (line.Bold ? bigBold : bigNormal) : (line.Bold ? bold : normal);
                var cols = big ? columns / 2 : columns;
                var height = Height(g, line, font, cols, blockWidth);

                // Next page if it does not fit (thermal rolls are long enough that this is rare).
                if (y > 0 && y + height > area.Height)
                {
                    e.HasMorePages = true;
                    return;
                }

                Draw(g, line, font, cols, left, y, blockWidth, lineHeight);
                y += height;
                next++;
            }

            e.HasMorePages = false;
        }

        private static float Height(Graphics g, DriverLine line, Font font, int cols, float width) => line.Type switch
        {
            "feed" => font.GetHeight(g) * Math.Max(1, line.Lines),
            "cut" => 0,
            "qr" => QrSize(line, width) + (line.Caption is null ? 0 : font.GetHeight(g)) + 6,
            "image" => ImageSize(line, width).Height + 6,
            "row" or "divider" => font.GetHeight(g),
            _ => font.GetHeight(g) * Wrap(line.Text ?? "", cols).Count,
        };

        private static void Draw(Graphics g, DriverLine line, Font font, int cols, float left, float y, float width, float lineHeight)
        {
            var format = StringFormat.GenericTypographic;

            switch (line.Type)
            {
                case "feed":
                case "cut":
                    return;
                case "divider":
                    g.DrawString(new string((line.Char ?? "-")[0], cols), font, Brushes.Black, left, y, format);
                    return;
                case "row":
                    g.DrawString(Row(line.Left ?? "", line.Right ?? "", cols), font, Brushes.Black, left, y, format);
                    return;
                case "qr":
                    DrawQr(g, line, left, y, width, font);
                    return;
                case "image":
                    DrawImage(g, line, left, y, width);
                    return;
            }

            var lines = Wrap(line.Text ?? "", cols);
            var height = font.GetHeight(g);

            if (line.Invert)
            {
                g.FillRectangle(Brushes.Black, left, y, width, height * lines.Count);
            }

            for (var i = 0; i < lines.Count; i++)
            {
                var text = lines[i];
                var textWidth = g.MeasureString(text, font, PointF.Empty, format).Width;
                var x = line.Align switch
                {
                    "center" => left + (width - textWidth) / 2,
                    "right" => left + width - textWidth,
                    _ => left,
                };
                g.DrawString(text, font, line.Invert ? Brushes.White : Brushes.Black, x, y + i * height, format);
            }
        }

        private static float QrSize(DriverLine line, float width)
        {
            var modules = line.Matrix?.Count ?? 0;

            return modules == 0 ? 0 : Math.Min(width * 0.45f, 160f);
        }

        private static void DrawQr(Graphics g, DriverLine line, float left, float y, float width, Font font)
        {
            var matrix = line.Matrix;

            if (matrix is null || matrix.Count == 0)
            {
                return;
            }

            var size = QrSize(line, width);
            var module = size / matrix.Count;
            var x0 = left + (width - size) / 2;

            for (var row = 0; row < matrix.Count; row++)
            {
                for (var col = 0; col < matrix[row].Length; col++)
                {
                    if (matrix[row][col] == '1')
                    {
                        g.FillRectangle(Brushes.Black, x0 + col * module, y + 3 + row * module, module + 0.3f, module + 0.3f);
                    }
                }
            }

            if (line.Caption is not null)
            {
                var captionWidth = g.MeasureString(line.Caption, font).Width;
                g.DrawString(line.Caption, font, Brushes.Black, left + (width - captionWidth) / 2, y + 3 + size);
            }
        }

        private static SizeF ImageSize(DriverLine line, float width)
        {
            using var image = LoadImage(line);

            if (image is null)
            {
                return SizeF.Empty;
            }

            var w = Math.Min(width * 0.6f, 200f);

            return new SizeF(w, w * image.Height / image.Width);
        }

        private static void DrawImage(Graphics g, DriverLine line, float left, float y, float width)
        {
            using var image = LoadImage(line);

            if (image is null)
            {
                return;
            }

            var size = ImageSize(line, width);
            g.DrawImage(image, left + (width - size.Width) / 2, y + 3, size.Width, size.Height);
        }

        private static Image? LoadImage(DriverLine line)
        {
            if (string.IsNullOrEmpty(line.Png))
            {
                return null;
            }

            try
            {
                return Image.FromStream(new MemoryStream(Convert.FromBase64String(line.Png)));
            }
            catch
            {
                return null;
            }
        }

        private static string Row(string left, string right, int cols)
        {
            var space = cols - left.Length - right.Length;

            return space >= 1 ? left + new string(' ', space) + right : left[..Math.Max(0, cols - right.Length - 1)] + " " + right;
        }

        private static List<string> Wrap(string text, int cols)
        {
            var lines = new List<string>();

            foreach (var paragraph in text.Split('\n'))
            {
                var current = "";

                foreach (var word in paragraph.Split(' '))
                {
                    var candidate = current.Length == 0 ? word : current + " " + word;

                    if (candidate.Length <= cols)
                    {
                        current = candidate;
                        continue;
                    }

                    if (current.Length > 0)
                    {
                        lines.Add(current);
                    }

                    current = word;

                    while (current.Length > cols)
                    {
                        lines.Add(current[..cols]);
                        current = current[cols..];
                    }
                }

                lines.Add(current);
            }

            return lines;
        }
    }
}
