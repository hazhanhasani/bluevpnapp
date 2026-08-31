from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def test_windows_endpoint_probes_reuse_short_lived_dns_results():
    source = (ROOT / "bluevpn-windows/Services/EndpointSelector.cs").read_text()
    assert "ConcurrentDictionary<string, DnsCacheEntry>" in source
    assert "TimeSpan.FromMinutes(5)" in source
    assert "ResolveAddressesAsync(host" in source
    assert "cached.ExpiresAt > now" in source


def test_windows_telemetry_has_clear_lightweight_card_hierarchy():
    source = (ROOT / "bluevpn-windows/App.xaml").read_text()
    style = source[source.index('x:Key="BlueVpnMetricCardStyle"'):]
    assert 'CornerRadius" Value="16"' in style
    assert 'BlueVpnSurface' in style and 'BlueVpnStroke' in style
    assert "DropShadowEffect" not in style
