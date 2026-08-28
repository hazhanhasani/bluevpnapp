from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AndroidLocationsRecyclerFoundationTest(unittest.TestCase):
    def text(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_recyclerview_dependency_is_explicit(self):
        prepare = self.text("scripts/prepare_android.py")
        self.assertIn(
            'implementation("androidx.recyclerview:recyclerview:1.4.0")',
            prepare,
        )

    def test_flattened_rows_have_stable_identity_and_content_version(self):
        row = self.text("android-source/BlueVpnLocationListRow.kt")
        self.assertIn('override val stableId: String = "country:$locationKey"', row)
        self.assertIn('override val stableId: String = "server:$guid"', row)
        self.assertIn("override val contentVersion", row)
        self.assertIn("BlueVpnLatencyPhase", row)

    def test_diffutil_separates_identity_from_content(self):
        diff = self.text("android-source/BlueVpnLocationRowDiff.kt")
        self.assertIn("DiffUtil.ItemCallback<BlueVpnLocationListRow>()", diff)
        self.assertIn("oldItem.stableId == newItem.stableId", diff)
        self.assertIn("oldItem.contentVersion == newItem.contentVersion", diff)

    def test_new_sources_are_packaged_into_android_overlay(self):
        prepare = self.text("scripts/prepare_android.py")
        for name in ["BlueVpnLocationListRow.kt", "BlueVpnLocationRowDiff.kt"]:
            self.assertIn(
                f'bluevpn_dir / "{name}": ROOT / "android-source/{name}"',
                prepare,
            )


if __name__ == "__main__":
    unittest.main()
