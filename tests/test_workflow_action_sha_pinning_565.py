import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = ROOT / ".github" / "workflows"


class WorkflowActionShaPinningTests(unittest.TestCase):
    def test_every_external_action_is_pinned_to_full_commit_sha(self):
        invalid = []
        pattern = re.compile(r"^\s*-?\s*uses:\s+([^\s#]+)@([^\s#]+)", re.MULTILINE)
        for workflow in sorted(WORKFLOWS.glob("*.yml")):
            for action, revision in pattern.findall(workflow.read_text(encoding="utf-8")):
                if action.startswith("./"):
                    continue
                if not re.fullmatch(r"[0-9a-f]{40}", revision):
                    invalid.append(f"{workflow.name}: {action}@{revision}")
        self.assertEqual(invalid, [], "unpinned GitHub Actions: " + ", ".join(invalid))


if __name__ == "__main__":
    unittest.main()
