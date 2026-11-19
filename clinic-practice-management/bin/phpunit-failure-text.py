#!/usr/bin/env python3
"""Best-effort public PHPUnit diagnostics; never use message text as a fallback."""

import os
import re
import sys
import xml.etree.ElementTree as ET
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PLUGIN = 'clinic-practice-management'
EXCEPTIONS = frozenset((r'PHPUnit\Framework\ExpectationFailedException',
                        r'PHPUnit\Framework\AssertionFailedError', r'PHPUnit\Framework\Exception',
                        'RuntimeException', 'LogicException', 'TypeError', 'Error', 'Exception'))
NEUTRAL = 'No PHPUnit failure details available.'
UNKNOWN = 'unverified test name'
IDENT = r'[A-Za-z_][A-Za-z0-9_]*'
FRAME = re.compile(r'(clinic-practice-management/(?:tests|src)/[A-Za-z0-9_./-]+\.php):([1-9][0-9]{0,6})')


class NoDTD(ET.TreeBuilder):
    def doctype(self, *args):
        raise ValueError('DTD not accepted')


@lru_cache(maxsize=128)
def source(rel):
    path = ROOT / rel
    # No dot segments, symlinks or aliases, even when the destination exists.
    try:
        if path.resolve() == path and path.relative_to(ROOT).as_posix() == rel and path.is_file():
            return path.read_text(encoding='utf-8')
    except (OSError, UnicodeError, RuntimeError):
        pass
    return ''


# Only accept simple PSR-4 test declarations in the checkout. Strip strings
# and comments before counting braces so example declarations are not methods.
NON_CODE = re.compile(r'''/\*.*?\*/|//[^\n]*|\#(?!\[)[^\n]*|'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|`(?:\\.|[^`\\])*`''', re.S)


@lru_cache(maxsize=128)
def methods(cls):
    if not re.fullmatch(r'ClinicCore\\Tests\\(?:' + IDENT + r'\\)*' + IDENT + 'Test', cls):
        return set()
    namespace, short = cls.rsplit('\\', 1)
    rel = cls.removeprefix('ClinicCore\\Tests\\').replace('\\', '/')
    code = NON_CODE.sub(' ', source(f'{PLUGIN}/tests/{rel}.php'))
    # Unsupported PHP forms (e.g. embedded HTML or heredocs) fail closed.
    if '<<<' in code or '?>' in code or re.findall(r'\bnamespace\s+([\w\\]+)\s*[;{]', code) != [namespace]:
        return set()
    decl = re.search(r'(?m)^\s*(?:final\s+)?class\s+' + short + r'\s+extends\s+[\w\\]+\s*\{', code)
    if not decl:
        return set()
    depth, found = 1, set()
    for token in re.finditer(r'[{}]|\bpublic\s+function\s+(test\w*)\s*\(', code[decl.end():], re.ASCII):
        if token[0] == '{':
            depth += 1
        elif token[0] == '}':
            depth -= 1
            if depth == 0:
                break
        elif depth == 1:
            found.add(token[1])
    return found


def test_id(case):
    cls = case.get('class') or case.get('classname', '').replace('.', '\\')
    method = case.get('name', '').partition(' with data set ')[0]
    return f'{cls}::{method}' if method in methods(cls) else UNKNOWN


def location(text):
    # PHPUnit appends filtered path:line frames after the description. Walk
    # only the trailing frame block, not path-shaped lines inside the message.
    frames = []
    for line in reversed(text.rstrip().splitlines()):
        if not re.fullmatch(r'(?:/|clinic-practice-management/)[^\r\n]+:(?:[0-9]+|\?)', line):
            break
        frames.append(line)
    for line in reversed(frames):
        # PHPUnit emits a bare path:line; never search for substrings in URLs,
        # foreign absolute paths, exception descriptions or assertion values.
        match = FRAME.fullmatch(line.removeprefix(str(ROOT) + '/'))
        if match:
            rel, number = match.groups()
            code = source(rel)
            if 0 < int(number) <= len(code.splitlines()):
                return f'{rel}:{number}'
    return ''


def escape(value, title=False):
    value = value.replace('%', '%25').replace('\r', '%0D').replace('\n', '%0A')
    return value.replace(':', '%3A').replace(',', '%2C') if title else value


def report(path):
    if Path(path).stat().st_size > 32 * 1024 * 1024:
        print(NEUTRAL)
        return
    root = ET.parse(path, parser=ET.XMLParser(target=NoDTD())).getroot()
    rows, count = [], 0
    for case in root.iter('testcase'):
        # Skipped-only cases are not failures and must not crowd out red tests.
        node = next((n for n in case if n.tag in ('failure', 'error', 'warning', 'risky')), None)
        if node is None:
            continue
        count += 1
        if count <= 30:
            exc = node.get('type')
            rows.append((test_id(case), node.tag, exc if exc in EXCEPTIONS else 'other', location(node.text or '')))
    if not rows:
        print(NEUTRAL)
        return
    for name, *fields in rows[:10]:
        print(f'::error title={escape(name, title=True)}::{escape(" · ".join(filter(None, fields)))}')
    if summary := os.environ.get('GITHUB_STEP_SUMMARY'):
        with open(summary, 'a', encoding='utf-8') as out:
            print('### PHPUnit failures\n\n| Test | Category | Exception | Location |\n|---|---|---|---|', file=out)
            for row in rows:
                # Every field above excludes backticks, pipes and line breaks.
                print('| ' + ' | '.join(f'`{v}`' if v else '' for v in row) + ' |', file=out)
            if count > len(rows):
                print(f'\n{count - len(rows)} more not shown', file=out)


if __name__ == '__main__':
    try:
        report(sys.argv[1])
    except Exception:
        # Errors can contain paths, environmental values or fragments of XML.
        print(NEUTRAL)
