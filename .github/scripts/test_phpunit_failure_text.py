"""Exercise both public surfaces via the actual CLI and a synthetic checkout."""

import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
import xml.etree.ElementTree as ET

SCRIPT = Path(__file__).with_name('phpunit-failure-text.py')
REL = 'clinic-practice-management/tests/Unit/SampleTest.php'
CLS = r'ClinicCore\Tests\Unit\SampleTest'
CANARIES = ['SECRET_CANARY', 'SELECT private_value', '0' * 10, 'https://invalid.example/private']
SOURCE = r'''<?php
namespace ClinicCore\Tests\Unit;
final class SampleTest extends TestCase {
    // public function testComment() {}
    public function testReal() { $s = 'public function testString() {}'; }
    private function helper() {}
}
class Neighbor { public function testNeighbor() {} }
'''


def case(tag='failure', exc='RuntimeException', text='', name='testReal', cls=CLS):
    c = ET.Element('testcase', {'class': cls, 'name': name})
    ET.SubElement(c, tag, type=exc, message=' '.join(CANARIES)).text = text
    ET.SubElement(c, 'system-out').text = ' '.join(CANARIES)
    return ET.tostring(c, encoding='unicode')


class PublicationTests(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.root = Path(tmp.name).resolve()
        self.script = self.root / '.github/scripts' / SCRIPT.name
        self.script.parent.mkdir(parents=True)
        shutil.copyfile(SCRIPT, self.script)
        self.source = self.root / REL
        self.source.parent.mkdir(parents=True)
        self.source.write_text(SOURCE)
        self.summary = self.root / 'summary'

    def invoke(self, xml, *, wrap=True, summary=None):
        path = self.root / 'private-junit.xml'
        if xml is None:
            path.unlink(missing_ok=True)
        else:
            path.write_text(f'<testsuite>{xml}</testsuite>' if wrap else xml)
        self.summary.unlink(missing_ok=True)
        run = subprocess.run([sys.executable, self.script, path], text=True, capture_output=True,
                             env={**os.environ, 'GITHUB_STEP_SUMMARY': str(summary or self.summary)})
        self.assertEqual(run.stderr, '')
        self.assertEqual(run.returncode, 0)
        return run.stdout, self.summary.read_text() if self.summary.exists() else ''

    def test_source_verified_failure_and_first_eligible_frame(self):
        """Publishing the provider suffix, a later frame, a message, or dropping the allowed class fails this test."""
        exc = r'PHPUnit\Framework\ExpectationFailedException'
        frames = f'{REL}:2\n' + ' '.join(CANARIES) + f'\n/vendor/shim.php:3\n{self.source}:5\n{REL}:6'
        stdout, table = self.invoke(case(exc=exc, text=frames, name='testReal with data set "SECRET_CANARY"'))
        self.assertEqual(stdout, f'::error title={CLS}%3A%3AtestReal::failure · {exc} · {REL}:5\n')
        self.assertIn(f'| `{CLS}::testReal` | `failure` | `{exc}` | `{REL}:5` |', table)
        for sentinel in CANARIES:
            self.assertNotIn(sentinel, stdout + table)

    def test_error_class_and_name_are_closed(self):
        """Accepting exceptions by suffix, methods from another class, helpers, strings or comments turns this red."""
        for name in ('testComment', 'testNeighbor', 'testString', 'helper', 'testMadeUp%:,\n'):
            with self.subTest(name=name):
                stdout, table = self.invoke(case('error', 'WPDieException', name=name))
                self.assertEqual(stdout, '::error title=unverified test name::error · other\n')
                self.assertIn('| `unverified test name` | `error` | `other` |  |', table)
        stdout, _ = self.invoke(case('error', r'Unknown\RuntimeException', cls=r'ClinicCore\Tests\Unit\OtherTest'))
        self.assertEqual(stdout, '::error title=unverified test name::error · other\n')

    def test_closed_exception_vocabulary(self):
        """Dropping an approved class or shortening a fully qualified class fails its exact comparison."""
        for exc in (r'PHPUnit\Framework\ExpectationFailedException', r'PHPUnit\Framework\AssertionFailedError',
                    r'PHPUnit\Framework\Exception', 'RuntimeException', 'LogicException', 'TypeError', 'Error', 'Exception'):
            with self.subTest(exc=exc):
                stdout, table = self.invoke(case('error', exc))
                self.assertEqual(stdout, f'::error title={CLS}%3A%3AtestReal::error · {exc}\n')
                self.assertIn(f'| `error` | `{exc}` |', table)

    def test_bad_locations_omitted(self):
        """Allowing traversal, foreign or nonexistent paths, symlinks, or non-source line numbers leaks a location."""
        self.source.with_name('Alias.php').symlink_to(self.source)
        for frame in (str(self.script) + ':2', REL + ':nan', REL + ':999999', '/elsewhere/' + REL + ':3',
                      REL.replace('Unit/', 'Unit/../Unit/') + ':4', REL.replace('SampleTest', 'Alias') + ':1',
                      REL.replace('SampleTest', 'Missing') + ':1', REL.replace('Unit/', 'bad|/') + ':1'):
            with self.subTest(frame=frame):
                stdout, table = self.invoke(case(text=frame))
                self.assertEqual(stdout, f'::error title={CLS}%3A%3AtestReal::failure · RuntimeException\n')
                self.assertIn('| `RuntimeException` |  |', table)

    def test_output_caps_and_count(self):
        """Removing either independent cap or counting annotations rather than omitted table rows fails here."""
        stdout, table = self.invoke(case('skipped') + case() * 33)
        self.assertEqual(len(stdout.splitlines()), 10)
        self.assertEqual(stdout.count('::error title='), 10)
        self.assertEqual(table.count('| `failure` |'), 30)
        self.assertTrue(table.endswith('\n3 more not shown\n'))

    def test_missing_empty_malformed_dtd_or_no_failure(self):
        """Accepting a DTD, reporting a skip as a failure, or echoing parser errors breaks the neutral contract."""
        for raw in (None, '', '<testsuite>', '<testsuite/>', '<testsuite>' + case('skipped') + '</testsuite>',
                    '<!DOCTYPE testsuite [<!ENTITY ext SYSTEM "file:///SECRET_CANARY">]><testsuite>' + case() + '</testsuite>'):
            with self.subTest(raw=raw):
                self.assertEqual(self.invoke(raw, wrap=False), ('No PHPUnit failure details available.\n', ''))

    def test_broken_summary_is_nonfatal(self):
        """Propagating the summary write error produces a nonzero status or a traceback."""
        stdout, _ = self.invoke(case(), summary=self.root)
        self.assertIn(f'::error title={CLS}%3A%3AtestReal::failure · RuntimeException', stdout)
        self.assertNotIn(str(self.root), stdout)


if __name__ == '__main__':
    unittest.main()
