#!/usr/bin/env python3
"""One-shot migration: collapse BlueVPN Actions into one visible pipeline.

This script is intentionally run only on the migration branch. It reads the
existing workflow files locally, preserves their jobs/steps, prefixes job IDs to
avoid collisions, adds a real generated-Android Kotlin compile gate, removes the
old cross-workflow fan-out, and leaves exactly one workflow file.
"""
from __future__ import annotations

from copy import deepcopy
from pathlib import Path
import re

from ruamel.yaml import YAML
from ruamel.yaml.comments import CommentedMap, CommentedSeq

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW_DIR = ROOT / ".github" / "workflows"
UNIFIED = WORKFLOW_DIR / "bluevpn.yml"

OLD_WORKFLOWS = [
    "bluevpn-manager-release.yml",
    "bluevpn-sentinel.yml",
    "bluevpn-site-theme-release.yml",
    "build-apk.yml",
    "build-ios.yml",
    "build-windows.yml",
    "external-health.yml",
    "project-health.yml",
]


def _sanitize(value: str) -> str:
    value = re.sub(r"[^A-Za-z0-9_]", "_", value)
    if not value or value[0].isdigit():
        value = "job_" + value
    return value


def _expr(value: object) -> str:
    text = str(value or "").strip()
    if text.startswith("${{") and text.endswith("}}"):
        text = text[3:-2].strip()
    return text


def _guarded(existing: object, guard: str) -> str:
    old = _expr(existing)
    body = f"({guard})"
    if old:
        body += f" && ({old})"
    return "${{ " + body + " }}"


def _rewrite_strings(value, replacements: dict[str, str]):
    if isinstance(value, str):
        out = value
        for old, new in replacements.items():
            out = out.replace(f"needs.{old}.", f"needs.{new}.")
            out = out.replace(f"needs['{old}'].", f"needs['{new}'].")
            out = out.replace(f'needs["{old}"].', f'needs["{new}"].')
        return out
    if isinstance(value, list):
        for i, item in enumerate(value):
            value[i] = _rewrite_strings(item, replacements)
        return value
    if isinstance(value, dict):
        for key in list(value.keys()):
            value[key] = _rewrite_strings(value[key], replacements)
        return value
    return value


def _merge_job_context(job: CommentedMap, doc: CommentedMap) -> None:
    top_env = doc.get("env")
    if isinstance(top_env, dict):
        merged = CommentedMap()
        for k, v in top_env.items():
            merged[k] = deepcopy(v)
        if isinstance(job.get("env"), dict):
            for k, v in job["env"].items():
                merged[k] = v
        job["env"] = merged
    if "defaults" not in job and isinstance(doc.get("defaults"), dict):
        job["defaults"] = deepcopy(doc["defaults"])


def _source_guard(filename: str) -> str:
    manual = "github.event_name == 'workflow_dispatch'"
    target = "inputs.target"
    main_push = "github.event_name == 'push' && github.ref == 'refs/heads/main'"
    if filename == "project-health.yml":
        return (
            "github.event_name == 'push' || github.event_name == 'pull_request' || "
            f"({manual} && ({target} == 'full' || {target} == 'health')) || "
            "github.event_name == 'repository_dispatch'"
        )
    if filename == "build-apk.yml":
        return (
            f"({main_push}) || github.event_name == 'repository_dispatch' || "
            f"({manual} && ({target} == 'full' || {target} == 'android'))"
        )
    if filename == "build-windows.yml":
        return f"({main_push}) || ({manual} && ({target} == 'full' || {target} == 'windows'))"
    if filename == "build-ios.yml":
        return f"({main_push}) || ({manual} && ({target} == 'full' || {target} == 'ios'))"
    if filename == "bluevpn-manager-release.yml":
        return f"({main_push}) || ({manual} && ({target} == 'full' || {target} == 'manager'))"
    if filename == "bluevpn-site-theme-release.yml":
        return f"({main_push}) || ({manual} && ({target} == 'full' || {target} == 'theme'))"
    if filename == "external-health.yml":
        return (
            "github.event_name == 'schedule' || "
            f"({manual} && ({target} == 'full' || {target} == 'external-health'))"
        )
    return "false"


def _merge_permissions(docs: dict[str, CommentedMap]) -> CommentedMap:
    rank = {"none": 0, "read": 1, "write": 2}
    merged: dict[str, str] = {}
    for doc in docs.values():
        permissions = doc.get("permissions")
        if not isinstance(permissions, dict):
            continue
        for key, value in permissions.items():
            val = str(value)
            if rank.get(val, 0) > rank.get(merged.get(str(key), "none"), 0):
                merged[str(key)] = val
    # Release jobs create releases/tags and Sentinel reads Actions/checks.
    merged["contents"] = "write"
    merged["actions"] = "write"
    return CommentedMap(sorted(merged.items()))


def _merge_dispatch_inputs(docs: dict[str, CommentedMap]) -> CommentedMap:
    inputs = CommentedMap()
    inputs["target"] = CommentedMap(
        {
            "description": "Pipeline target",
            "required": True,
            "default": "full",
            "type": "choice",
            "options": [
                "full", "health", "android", "windows", "ios", "manager",
                "theme", "external-health", "sentinel",
            ],
        }
    )
    for doc in docs.values():
        on = doc.get("on")
        if not isinstance(on, dict):
            continue
        dispatch = on.get("workflow_dispatch")
        if not isinstance(dispatch, dict):
            continue
        source_inputs = dispatch.get("inputs")
        if not isinstance(source_inputs, dict):
            continue
        for key, value in source_inputs.items():
            if str(key) == "target":
                continue
            if key not in inputs:
                inputs[key] = deepcopy(value)
    if "build_mode" not in inputs:
        inputs["build_mode"] = CommentedMap(
            {
                "description": "Android publication mode",
                "required": True,
                "default": "full",
                "type": "choice",
                "options": ["full", "fast"],
            }
        )
    if "target_sha" not in inputs:
        inputs["target_sha"] = CommentedMap(
            {"description": "Optional exact source SHA", "required": False, "type": "string"}
        )
    return inputs


def _merge_repository_dispatch_types(docs: dict[str, CommentedMap]) -> list[str]:
    types: list[str] = []
    for doc in docs.values():
        on = doc.get("on")
        if not isinstance(on, dict):
            continue
        rd = on.get("repository_dispatch")
        if not isinstance(rd, dict):
            continue
        for value in rd.get("types", []) or []:
            text = str(value)
            if text not in types:
                types.append(text)
    if "bluevpn_build" not in types:
        types.append("bluevpn_build")
    return types


def _merge_schedules(docs: dict[str, CommentedMap]) -> CommentedSeq:
    seen: set[str] = set()
    out = CommentedSeq()
    for doc in docs.values():
        on = doc.get("on")
        if not isinstance(on, dict):
            continue
        schedule = on.get("schedule")
        if not isinstance(schedule, list):
            continue
        for item in schedule:
            if not isinstance(item, dict) or "cron" not in item:
                continue
            cron = str(item["cron"])
            if cron in seen:
                continue
            seen.add(cron)
            out.append(CommentedMap({"cron": cron}))
    return out


def _android_compile_job(android_doc: CommentedMap) -> CommentedMap:
    jobs = android_doc.get("jobs") or {}
    if not jobs:
        raise RuntimeError("Android workflow has no jobs")
    source = deepcopy(next(iter(jobs.values())))
    _merge_job_context(source, android_doc)
    source["name"] = "Android generated-source Kotlin compile gate"
    source["if"] = "${{ github.event_name == 'pull_request' || (github.event_name == 'push' && github.ref != 'refs/heads/main') || (github.event_name == 'workflow_dispatch' && (inputs.target == 'health' || inputs.target == 'full')) }}"
    source.pop("concurrency", None)

    if isinstance(source.get("env"), dict):
        for key in [
            "ANDROID_KEYSTORE_BASE64",
            "ANDROID_KEYSTORE_PASSWORD",
            "ANDROID_KEY_ALIAS",
            "ANDROID_KEY_PASSWORD",
        ]:
            source["env"].pop(key, None)

    kept = CommentedSeq()
    found_compile = False
    for step in source.get("steps", []) or []:
        name = str(step.get("name", "")) if isinstance(step, dict) else ""
        if name in {
            "Check permanent signing secrets",
            "Persist resolved version in repository",
        }:
            continue
        cloned = deepcopy(step)
        cloned = _rewrite_strings(
            cloned,
            {},
        )
        if isinstance(cloned, dict):
            # The compile-only PR gate must never write release metadata back.
            for container in [cloned.get("env")]:
                if isinstance(container, dict):
                    for key, value in list(container.items()):
                        if isinstance(value, str):
                            container[key] = value.replace(
                                "${{ steps.persist_metadata.outputs.source_sha }}",
                                "${{ github.sha }}",
                            )
        kept.append(cloned)
        if name == "Build unsigned release APKs":
            found_compile = True
            break
    if not found_compile:
        raise RuntimeError("Android compile step was not found")
    source["steps"] = kept
    return source


def _patch_android_coexistence() -> None:
    home = ROOT / "android-source" / "BlueVpnHomeActivity.kt"
    text = home.read_text(encoding="utf-8")
    marker = "// BLUEVPN_FOREIGN_VPN_COEXISTENCE_V5106"
    if marker not in text:
        anchor = "    private fun beginSmartConnection() {\n"
        if anchor not in text:
            raise RuntimeError("beginSmartConnection anchor not found")
        guard = '''    private fun beginSmartConnection() {\n        // BLUEVPN_FOREIGN_VPN_COEXISTENCE_V5106\n        // Android supports a single active VPN owner. Never let background\n        // retry/recovery (or an accidental system callback) revoke another VPN.\n        // The user can disconnect the other VPN first, then connect BlueVPN.\n        if (\n            mainViewModel.isRunning.value != true &&\n            !BlueVpnRuntimeGate.connectionActive(this) &&\n            BlueVpnRuntimeGate.otherVpnActive(this)\n        ) {\n            pendingConnectionRequest = false\n            runtimeGateRetryScheduled = false\n            runtimeGateWaitStartedAt = 0L\n            recoveryCleanupRequired = false\n            hideConnectingOverlay()\n            connectButton.isEnabled = true\n            updateConnectLabel("اتصال")\n            statusText.text = "یک VPN دیگر فعال است"\n            statusCaption.visibility = View.VISIBLE\n            statusCaption.text = "BlueVPN برای جلوگیری از قطع VPN فعلی شما شروع نشد"\n            return\n        }\n'''
        text = text.replace(anchor, guard, 1)
        home.write_text(text, encoding="utf-8")


def _rewrite_workflow_test_paths() -> None:
    for path in (ROOT / "tests").rglob("*.py"):
        text = path.read_text(encoding="utf-8")
        updated = text
        for old in OLD_WORKFLOWS:
            updated = updated.replace(f".github/workflows/{old}", ".github/workflows/bluevpn.yml")
        # Bare workflow names in path-oriented tests also need the unified file.
        # Do not rewrite gh workflow run assertions; those are obsolete fan-out
        # contracts and should fail visibly instead of being silently preserved.
        if updated != text:
            path.write_text(updated, encoding="utf-8")


def main() -> None:
    yaml = YAML()
    yaml.preserve_quotes = True
    yaml.width = 4096

    docs: dict[str, CommentedMap] = {}
    for filename in OLD_WORKFLOWS:
        path = WORKFLOW_DIR / filename
        if not path.is_file():
            raise RuntimeError(f"missing workflow: {filename}")
        with path.open("r", encoding="utf-8") as fh:
            doc = yaml.load(fh)
        if not isinstance(doc, dict):
            raise RuntimeError(f"invalid workflow mapping: {filename}")
        docs[filename] = doc

    unified = CommentedMap()
    unified["name"] = "BlueVPN Unified Pipeline"

    trigger = CommentedMap()
    trigger["push"] = CommentedMap()
    trigger["pull_request"] = CommentedMap()
    trigger["workflow_dispatch"] = CommentedMap({"inputs": _merge_dispatch_inputs(docs)})
    rd_types = _merge_repository_dispatch_types(docs)
    if rd_types:
        trigger["repository_dispatch"] = CommentedMap({"types": rd_types})
    schedules = _merge_schedules(docs)
    if schedules:
        trigger["schedule"] = schedules
    unified["on"] = trigger
    unified["permissions"] = _merge_permissions(docs)
    unified["concurrency"] = CommentedMap(
        {
            "group": "bluevpn-unified-${{ github.event_name }}-${{ github.ref || github.run_id }}",
            "cancel-in-progress": True,
        }
    )

    jobs_out = CommentedMap()
    all_release_job_ids: list[str] = []

    for filename, doc in docs.items():
        if filename == "bluevpn-sentinel.yml":
            continue
        source_jobs = doc.get("jobs")
        if not isinstance(source_jobs, dict):
            continue
        prefix = _sanitize(Path(filename).stem)
        id_map = {str(old): f"{prefix}__{_sanitize(str(old))}" for old in source_jobs.keys()}

        for old_id, source_job in source_jobs.items():
            if filename == "project-health.yml" and str(old_id) == "fanout-main-builds":
                # Cross-workflow dispatch is exactly what this migration removes.
                continue
            new_id = id_map[str(old_id)]
            job = deepcopy(source_job)
            _merge_job_context(job, doc)

            needs = job.get("needs")
            if isinstance(needs, str):
                job["needs"] = id_map.get(needs, needs)
            elif isinstance(needs, list):
                job["needs"] = [id_map.get(str(x), str(x)) for x in needs]

            job = _rewrite_strings(job, id_map)
            job["if"] = _guarded(job.get("if"), _source_guard(filename))
            jobs_out[new_id] = job
            all_release_job_ids.append(new_id)

    compile_id = "android_generated_compile"
    jobs_out[compile_id] = _android_compile_job(docs["build-apk.yml"])
    all_release_job_ids.append(compile_id)

    # Convert the old workflow_run Sentinel into the final job of this same run.
    sentinel_jobs = docs["bluevpn-sentinel.yml"].get("jobs") or {}
    if sentinel_jobs:
        sentinel = deepcopy(next(iter(sentinel_jobs.values())))
        _merge_job_context(sentinel, docs["bluevpn-sentinel.yml"])
        sentinel["name"] = "BlueVPN Sentinel (unified run)"
        sentinel["needs"] = list(all_release_job_ids)
        sentinel["if"] = "${{ always() && github.event_name != 'pull_request' && (contains(needs.*.result, 'failure') || (github.event_name == 'workflow_dispatch' && inputs.target == 'sentinel')) }}"
        for step in sentinel.get("steps", []) or []:
            if not isinstance(step, dict):
                continue
            env = step.get("env")
            if isinstance(env, dict):
                if "RUN_ID" in env:
                    env["RUN_ID"] = "${{ github.run_id }}"
                if "WORKFLOW_NAME" in env:
                    env["WORKFLOW_NAME"] = "${{ github.workflow }}"
                if "RUN_URL" in env:
                    env["RUN_URL"] = "${{ github.server_url }}/${{ github.repository }}/actions/runs/${{ github.run_id }}"
        jobs_out["pipeline_sentinel"] = sentinel

    unified["jobs"] = jobs_out

    with UNIFIED.open("w", encoding="utf-8") as fh:
        yaml.dump(unified, fh)

    generated = UNIFIED.read_text(encoding="utf-8")
    if ":app:compilePlaystoreReleaseKotlin" not in generated:
        raise RuntimeError("unified pipeline lost the real Kotlin compiler gate")
    if "gh workflow run " in generated:
        raise RuntimeError("cross-workflow fan-out survived unified migration")
    if "android_generated_compile:" not in generated:
        raise RuntimeError("generated-source Android compile job missing")

    _patch_android_coexistence()
    _rewrite_workflow_test_paths()

    # Remove every legacy workflow and the one-shot migration workflow itself.
    for filename in OLD_WORKFLOWS:
        (WORKFLOW_DIR / filename).unlink(missing_ok=True)
    (WORKFLOW_DIR / "ci-migration.yml").unlink(missing_ok=True)

    remaining = sorted(path.name for path in WORKFLOW_DIR.glob("*.yml")) + sorted(
        path.name for path in WORKFLOW_DIR.glob("*.yaml")
    )
    if remaining != ["bluevpn.yml"]:
        raise RuntimeError(f"expected exactly one workflow, found: {remaining}")

    # Reparse the final file after all transformations.
    with UNIFIED.open("r", encoding="utf-8") as fh:
        check = yaml.load(fh)
    if not isinstance(check, dict) or "jobs" not in check:
        raise RuntimeError("unified workflow failed YAML round-trip validation")

    print(f"Unified pipeline generated with {len(jobs_out)} jobs")
    print("Remaining workflow: .github/workflows/bluevpn.yml")


if __name__ == "__main__":
    main()
