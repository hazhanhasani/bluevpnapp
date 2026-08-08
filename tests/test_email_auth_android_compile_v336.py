from __future__ import annotations

import ast
import base64
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def _embedded_subscriptions_source() -> str:
    prepare = ROOT / "scripts/prepare_android.py"
    module = ast.parse(prepare.read_text(encoding="utf-8"))
    for node in module.body:
        if not isinstance(node, ast.Assign) or len(node.targets) != 1:
            continue
        target = node.targets[0]
        if (
            isinstance(target, ast.Name)
            and target.id == "BLUEVPN_SUBSCRIPTIONS_ACTIVITY_B64"
        ):
            encoded = ast.literal_eval(node.value)
            return base64.b64decode(encoded).decode("utf-8")
    raise AssertionError("embedded subscriptions source not found")


def test_password_listener_is_registered_after_field_initialization():
    source = (
        ROOT / "android-source/BlueVpnSubscriptionsActivity.kt"
    ).read_text(encoding="utf-8")

    declaration = (
        'val password=authField("رمز عبور؛ حداقل ۸ کاراکتر").apply{'
    )
    listener = "password.setOnEditorActionListener{"

    assert declaration in source
    assert listener in source
    assert source.index(listener) > source.index(declaration)
    assert re.search(
        r'val password=authField\([^\n]+\)\.apply\{[^\n]*password\.text',
        source,
    ) is None


def test_embedded_generated_source_matches_fixed_snapshot():
    source = (
        ROOT / "android-source/BlueVpnSubscriptionsActivity.kt"
    ).read_text(encoding="utf-8")
    assert _embedded_subscriptions_source() == source
