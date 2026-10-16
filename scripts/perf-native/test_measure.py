import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import types
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('native_measure', Path(__file__).with_name('measure.py'))
measure = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(measure)


class NativeHarnessTest(unittest.TestCase):
    def test_nearest_rank_percentile(self):
        result = measure.stats(list(range(100, 0, -1)))
        self.assertEqual(result, {'count': 100, 'medianMs': 50.5, 'p95Ms': 95, 'maxMs': 100})
        self.assertIsNone(measure.stats([]))

    def test_request_is_pinned_to_loopback_with_validated_tls_and_no_redirect(self):
        calls = []
        def command(argv, **_kwargs):
            calls.append(argv)
            marker = argv[argv.index('--write-out') + 1].split('%{http_code}')[0]
            return b'<html>fixture</html>' + marker.encode() + b'200 0.012'
        with patch.object(measure, 'command', command):
            result = measure.request({'token': 'fixture-capability'}, 0, 2, 1, 4)
        argv = calls[0]
        self.assertEqual(argv[argv.index('--resolve') + 1], 'demo-shop.aithos.app:443:127.0.0.1')
        self.assertEqual(argv[argv.index('--max-redirs') + 1], '0')
        self.assertEqual(argv[-1], 'https://demo-shop.aithos.app/')
        self.assertNotIn('--insecure', argv)
        self.assertNotIn('--location', argv)
        self.assertEqual(result['clientMs'], 12)
        self.assertEqual(result['bodySha256'], measure.digest(b'<html>fixture</html>'))
        self.assertNotIn('fixture-capability', json.dumps(result))

    def test_neutral_probe_requires_a_matching_real_fpm_acknowledgement(self):
        def command(argv, **_kwargs):
            marker = argv[argv.index('--write-out') + 1].split('%{http_code}')[0]
            body = json.dumps({'sample': 1, 'delayMs': 2000, 'fpm': False}).encode()
            return body + marker.encode() + b'200 0.02'
        with patch.object(measure, 'command', command), self.assertRaises(measure.Failure):
            measure.request({'token': 'fixture', 'probePath': 'fixture'}, 0, 2, 0, 1, 2000)

    def test_kernel_probe_uses_the_actual_storefront_not_the_neutral_endpoint(self):
        def command(argv, **_kwargs):
            self.assertEqual(argv[-1], 'https://demo-shop.aithos.app/')
            self.assertIn('X-Agentenblick-Native-Delay: 2000', argv)
            marker = argv[argv.index('--write-out') + 1].split('%{http_code}')[0]
            return b'<html>Shopware</html>' + marker.encode() + b'200 0.02'
        with patch.object(measure, 'command', command):
            result = measure.request({'token': 'fixture'}, 0, 2, 0, 1, 2000, kernel=True)
        self.assertEqual(result['status'], 200)

    def test_shutdown_wait_ignores_an_incomplete_final_line(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            row = {'sample': 1, 'fpm': 1, 'php_version_id': 80300, 'open_timers': 0, 'post_ns': 123}
            output = root / 'samples.jsonl'
            output.write_text(json.dumps(row)[:-1])
            def finish(_seconds):
                output.write_text(json.dumps(row) + '\n')
            with patch.object(measure.time, 'sleep', finish):
                self.assertEqual(measure.shutdown_row(root, 1), row)

    def test_non_numeric_and_duplicate_shutdown_rows_fail_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            output = root / 'samples.jsonl'
            row = {'sample': 1, 'fpm': 1, 'php_version_id': 80300, 'open_timers': 0}
            for contents in [json.dumps({**row, 'visitor': 'forbidden'}) + '\n', (json.dumps(row) + '\n') * 2]:
                output.write_text(contents)
                with self.assertRaises(measure.Failure):
                    measure.shutdown_row(root, 1)

    def test_target_allowlist_rejects_escape_and_symlink(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            with self.assertRaises(measure.Failure):
                measure.target_path(root, '../another-shop/index.php')
            (root / 'public').mkdir()
            (root / 'outside.php').write_text('fixture')
            (root / 'public/index.php').symlink_to(root / 'outside.php')
            with self.assertRaises(measure.Failure):
                measure.target_path(root, 'public/index.php')

    def test_preparation_saves_candidates_without_touching_runtime(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve() / 'app'
            root.mkdir()
            (root / 'public').mkdir()
            source = root / 'public/index.php'
            original = b'<?php echo "fixture";'
            source.write_bytes(original)
            state = Path(directory) / 'state'
            state.mkdir()
            def command(argv, **_kwargs):
                if str(argv[1]).endswith('instrument.php'):
                    data = json.loads(Path(argv[2]).read_text())
                    for target in data['targets']:
                        Path(target['prepared']).write_bytes(original + b' // prepared')
                return b''
            args = types.SimpleNamespace(app_root=str(root), database='fixture_db', phase='terminate')
            stream = io.StringIO()
            with patch.object(measure, 'app_root', return_value=root), patch.object(measure, 'preflight', return_value={}), \
                 patch.object(measure, 'STATE', state), patch.object(measure, 'command', command), \
                 patch.object(measure.os, 'chown'), patch.object(measure.pwd, 'getpwnam', return_value=types.SimpleNamespace(pw_uid=33, pw_gid=33)), \
                 contextlib.redirect_stdout(stream):
                measure.prepare(args)
            result = json.loads(stream.getvalue())
            manifest = json.loads(Path(result['prepared']).read_text())
            self.assertFalse(result['runtimeModified'])
            self.assertEqual(source.read_bytes(), original)
            self.assertEqual(manifest['state'], 'prepared')
            self.assertEqual(Path(manifest['targets'][0]['backup']).read_bytes(), original)
            self.assertNotIn(manifest['token'], stream.getvalue())

    def fixture_manifest(self, directory, current=b'instrumented'):
        root = Path(directory)
        target = root / 'index.php'
        backup = root / 'backup.php'
        target.write_bytes(current)
        backup.write_bytes(b'original')
        entry = {'path': str(target), 'relative': 'public/index.php', 'backup': str(backup),
                 'originalSha256': measure.digest(b'original'), 'instrumentedSha256': measure.digest(b'instrumented'),
                 'mode': 0o644, 'uid': os.getuid(), 'gid': os.getgid()}
        return root / 'manifest.json', {'targets': [entry], 'database': 'fixture_db', 'originalPluginActive': True}, target

    def test_restoration_refuses_to_overwrite_an_external_deployment(self):
        with tempfile.TemporaryDirectory() as directory:
            path, manifest, target = self.fixture_manifest(directory, b'another-deployment')
            with patch.object(measure, 'reload_fpm') as reload_fpm:
                result = measure.restore(path, manifest, Path(directory))
            self.assertFalse(result['sourceHashesMatch'])
            self.assertEqual(target.read_bytes(), b'another-deployment')
            reload_fpm.assert_not_called()
            self.assertEqual(json.loads(path.read_text())['state'], 'restoration_incomplete')

    def test_restoration_is_idempotent_and_preserves_original_activation(self):
        with tempfile.TemporaryDirectory() as directory:
            path, manifest, target = self.fixture_manifest(directory)
            with patch.object(measure, 'bridge', return_value={'pluginActive': True}), \
                 patch.object(measure, 'reload_fpm'), patch.object(measure.os, 'chown'), \
                 patch.object(measure, 'plugin_state') as plugin_state:
                for _attempt in range(2):
                    result = measure.restore(path, manifest, Path(directory))
                    self.assertTrue(result['sourceHashesMatch'])
                    self.assertTrue(result['pluginActiveRestored'])
                    self.assertEqual(target.read_bytes(), b'original')
                plugin_state.assert_not_called()

    def test_kernel_gate_requires_both_client_release_and_actual_worker_delay(self):
        for valid_delay in [True, False]:
            with self.subTest(valid_delay=valid_delay), tempfile.TemporaryDirectory() as directory:
                path, manifest, _target = self.fixture_manifest(directory, b'original')
                manifest.update({'state': 'prepared', 'phase': 'kernel-terminate', 'token': 'fixture'})
                prepared = Path(directory) / 'prepared.php'
                prepared.write_bytes(b'instrumented')
                manifest['targets'][0]['prepared'] = str(prepared)
                def row(_directory, sample):
                    delayed = sample >= 4 and sample % 2 == 1
                    ns = 2000000000 if delayed and valid_delay else 0
                    return {'post_calls': 1, 'post_ns': ns + 100, 'kernel_delay_ns': ns}
                with patch.object(measure, 'preflight', return_value={}), patch.object(measure, 'reload_fpm'), \
                     patch.object(measure, 'bridge', side_effect=[{}, {'none': 14}]), \
                     patch.object(measure, 'request', return_value={'status': 200, 'clientMs': 25, 'bodyBytes': 100, 'bodySha256': 'fixture'}), \
                     patch.object(measure, 'shutdown_row', side_effect=row), patch.object(measure.os, 'chown'), \
                     patch.object(measure.time, 'sleep'), patch.object(measure.signal, 'signal'), \
                     patch.object(measure, 'restore', return_value={'sourceHashesMatch': True, 'pluginActiveRestored': True, 'errors': []}), \
                     contextlib.redirect_stdout(io.StringIO()):
                    status = measure.measure(path, manifest, Path(directory))
                report = json.loads((Path(directory) / 'report.json').read_text())
                self.assertEqual(status, 0 if valid_delay else 1)
                self.assertEqual(report['kernelTerminateReleasedClient'], valid_delay)
                self.assertEqual(len(report['warmups']), 4)
                self.assertEqual(len(report['samples']), 10)
                self.assertFalse(report['qualifiedProductionBudgets'])

    def test_no_execution_on_the_developer_mac(self):
        with patch.object(measure.sys, 'platform', 'darwin'), patch.object(measure.sys, 'argv', ['measure.py', 'preflight', '--database=fixture_db']), self.assertRaises(measure.Failure):
            measure.main()


if __name__ == '__main__':
    unittest.main()
