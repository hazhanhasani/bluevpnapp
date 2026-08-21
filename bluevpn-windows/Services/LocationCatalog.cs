using System.Text.RegularExpressions;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public sealed record LocationInfo(string Key, string Title, string Flag)
{
    public string Display => $"{Flag} {Title}";
}

public static class LocationCatalog
{
    private sealed record Rule(LocationInfo Location, string[] Aliases);

    private static readonly Rule[] Rules =
    [
        R("ca","کانادا","🇨🇦","ca","can","canada","کانادا","toronto","montreal","vancouver","quebec"),
        R("de","آلمان","🇩🇪","de","ger","germany","deutschland","آلمان","frankfurt","berlin","nuremberg","falkenstein","dusseldorf","düsseldorf","munich","hamburg"),
        R("nl","هلند","🇳🇱","nl","nld","netherlands","holland","هلند","amsterdam","rotterdam","dronten","meppel"),
        R("fi","فنلاند","🇫🇮","fi","fin","finland","فنلاند","helsinki"),
        R("fr","فرانسه","🇫🇷","fr","fra","france","فرانسه","paris","marseille","gravelines","strasbourg","roubaix","lyon","lille"),
        R("gb","انگلیس","🇬🇧","gb","uk","united kingdom","england","انگلیس","britain","london","manchester","coventry"),
        R("us","آمریکا","🇺🇸","us","usa","united states","america","آمریکا","new york","los angeles","miami","dallas","chicago","seattle","ashburn","phoenix"),
        R("tr","ترکیه","🇹🇷","tr","tur","turkey","türkiye","ترکیه","istanbul"),
        R("ae","امارات","🇦🇪","ae","uae","united arab emirates","امارات","dubai"),
        R("se","سوئد","🇸🇪","se","swe","sweden","سوئد","stockholm"),
        R("ch","سوئیس","🇨🇭","ch","che","switzerland","سوئیس","zurich"),
        R("jp","ژاپن","🇯🇵","jp","jpn","japan","ژاپن","tokyo"),
        R("sg","سنگاپور","🇸🇬","sg","sgp","singapore","سنگاپور"),
        R("ru","روسیه","🇷🇺","ru","rus","russia","روسیه","moscow"),
        R("at","اتریش","🇦🇹","at","aut","austria","اتریش","vienna"),
        R("be","بلژیک","🇧🇪","be","bel","belgium","بلژیک","brussels"),
        R("pl","لهستان","🇵🇱","pl","pol","poland","لهستان","warsaw"),
        R("es","اسپانیا","🇪🇸","es","esp","spain","اسپانیا","madrid"),
        R("it","ایتالیا","🇮🇹","it","ita","italy","ایتالیا","milan","rome"),
        R("no","نروژ","🇳🇴","no","nor","norway","نروژ","oslo"),
        R("dk","دانمارک","🇩🇰","dk","dnk","denmark","دانمارک","copenhagen"),
        R("cz","چک","🇨🇿","cz","cze","czech","czechia","چک","prague"),
        R("ro","رومانی","🇷🇴","ro","rou","romania","رومانی","bucharest"),
        R("bg","بلغارستان","🇧🇬","bg","bgr","bulgaria","بلغارستان","sofia"),
        R("ua","اوکراین","🇺🇦","ua","ukr","ukraine","اوکراین","kyiv"),
        R("in","هند","🇮🇳","in","ind","india","هند","mumbai","delhi"),
        R("hk","هنگ‌کنگ","🇭🇰","hk","hkg","hong kong","هنگ کنگ","هنگ‌کنگ"),
        R("kr","کره جنوبی","🇰🇷","kr","kor","south korea","korea","کره جنوبی","seoul"),
        R("au","استرالیا","🇦🇺","au","aus","australia","استرالیا","sydney"),
        R("br","برزیل","🇧🇷","br","bra","brazil","برزیل","sao paulo"),
        R("pt","پرتغال","🇵🇹","pt","prt","portugal","پرتغال","lisbon"),
        R("gr","یونان","🇬🇷","gr","grc","greece","یونان","athens"),
        R("ie","ایرلند","🇮🇪","ie","irl","ireland","ایرلند","dublin"),
        R("is","ایسلند","🇮🇸","is","isl","iceland","ایسلند"),
        R("sa","عربستان","🇸🇦","sa","sau","saudi arabia","عربستان","riyadh"),
        R("qa","قطر","🇶🇦","qa","qat","qatar","قطر","doha"),
        R("om","عمان","🇴🇲","om","omn","oman","عمان","muscat")
    ];

    public static LocationInfo? Detect(ProxyEndpoint endpoint) => Detect(endpoint.Name, endpoint.Host);

    public static LocationInfo? Detect(string? remarks, string? host)
    {
        var source = Normalize($"{remarks} {HostTokens(host)}");
        if (source.Length == 0) return null;
        foreach (var rule in Rules)
        {
            if (rule.Aliases.Any(alias => ContainsTokenOrPhrase(source, Normalize(alias))))
                return rule.Location;
        }
        return null;
    }

    public static IReadOnlyList<LocationInfo> Available(IReadOnlyList<ProxyEndpoint> endpoints) => endpoints
        .Select(Detect)
        .Where(x => x is not null)
        .Cast<LocationInfo>()
        .GroupBy(x => x.Key, StringComparer.OrdinalIgnoreCase)
        .Select(x => x.First())
        .OrderBy(x => x.Title, StringComparer.CurrentCulture)
        .ToList();

    private static Rule R(string key, string title, string flag, params string[] aliases) =>
        new(new LocationInfo(key, title, flag), aliases.Prepend(key).ToArray());

    private static string HostTokens(string? host) => (host ?? "").Replace('.', ' ').Replace('-', ' ').Replace('_', ' ');

    private static string Normalize(string value) => Regex.Replace(
        value.ToLowerInvariant().Replace('ي','ی').Replace('ك','ک').Replace('ۀ','ه').Replace('\u200c',' '),
        @"[_\-–—|/\\:;,\.\(\)\[\]\{\}<>+]+|\s+", " ").Trim();

    private static bool ContainsTokenOrPhrase(string source, string value)
    {
        if (value.Length == 0) return false;
        return $" {source} ".Contains($" {value} ", StringComparison.OrdinalIgnoreCase);
    }
}
