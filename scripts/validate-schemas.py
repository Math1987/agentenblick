#!/usr/bin/env python3
"""Run inside the disposable bench with python3-jsonschema installed."""
import json
from pathlib import Path
import subprocess
from jsonschema import Draft202012Validator, FormatChecker

root = Path(__file__).resolve().parents[1]
catalogue = json.loads((root / 'src/Resources/schema/catalog.schema.json').read_text())
report = json.loads((root / 'src/Resources/schema/report.schema.json').read_text())
for schema in [catalogue, report]:
    Draft202012Validator.check_schema(schema)
validator = Draft202012Validator(catalogue, format_checker=FormatChecker())
for path in ['src/Resources/catalog/agents.json', 'tests/fixtures/catalog.json']:
    validator.validate(json.loads((root / path).read_text()))
result = json.loads(subprocess.check_output(['php', '/var/www/html/bin/console', 'agentenblick:report', '--json'], text=True))
validator = Draft202012Validator(report, format_checker=FormatChecker())
validator.validate(result)
assert not validator.is_valid({**result, 'raw_ip': '198.51.100.20'})
print('Catalogue, test catalogue and live report: JSON Schema valid; forbidden report field rejected.')
