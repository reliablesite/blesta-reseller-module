#!/usr/bin/env python3
"""
Write a release version into every file that carries it.

The module states its version twice and Blesta reads both:

  config.json      "version"          - what the extension list shows, and what
                                        Blesta compares to decide whether to
                                        offer the Upgrade button
  reliablesite.php const RS_VERSION   - what Module::getVersion() returns, and
                                        what upgrade($current_version) is
                                        measured against

If the two disagree Blesta will happily install one and report the other, so
this is the single place that knows about both. Called by the release workflow
with the version taken from the tag, so a release can never ship a stale number.

Usage: set-version.py 2.4.0 [--check]

  --check  report what would change and exit non-zero if anything would,
           without writing. Useful in CI to assert a tag matches the tree.
"""

import json
import pathlib
import re
import sys

SEMVER = re.compile(r'^\d+\.\d+\.\d+$')

# file -> (pattern with the value as group 2, human description)
TARGETS = {
    'config.json': (
        re.compile(r'("version"\s*:\s*")([^"]*)(")'),
        'config.json "version"',
    ),
    'reliablesite.php': (
        re.compile(r"(const RS_VERSION\s*=\s*')([^']*)(')"),
        'reliablesite.php RS_VERSION',
    ),
}


def fail(message):
    # ::error:: renders as an annotation on the workflow run
    print('::error::%s' % message, file=sys.stderr)
    sys.exit(1)


def main():
    args = [a for a in sys.argv[1:] if a != '--check']
    check_only = '--check' in sys.argv[1:]

    if len(args) != 1:
        fail('usage: set-version.py <version> [--check]')

    version = args[0]
    if not SEMVER.match(version):
        fail("version '%s' is not MAJOR.MINOR.PATCH" % version)

    root = pathlib.Path(__file__).resolve().parents[2]
    changed = []

    for name, (pattern, label) in TARGETS.items():
        path = root / name
        if not path.is_file():
            fail('%s not found - has the module layout changed?' % name)

        source = path.read_text(encoding='utf-8')
        match = pattern.search(source)

        # A silent no-op here would ship the wrong version, so treat a missing
        # or duplicated match as fatal rather than carrying on.
        if match is None:
            fail('could not find the version in %s' % label)
        if len(pattern.findall(source)) != 1:
            fail('found more than one version in %s' % label)

        current = match.group(2)
        if current == version:
            print('  %-34s already %s' % (label, version))
            continue

        updated = pattern.sub(lambda m: m.group(1) + version + m.group(3), source, count=1)
        changed.append('%s: %s -> %s' % (label, current, version))

        if check_only:
            continue

        # config.json must survive the edit as valid JSON carrying the new value
        if name == 'config.json':
            try:
                parsed = json.loads(updated)
            except ValueError as e:
                fail('editing config.json produced invalid JSON: %s' % e)
            if parsed.get('version') != version:
                fail('config.json version did not take: %r' % parsed.get('version'))

        path.write_text(updated, encoding='utf-8')
        print('  %-34s %s -> %s' % (label, current, version))

    if check_only and changed:
        for line in changed:
            print('::error::out of date - %s' % line)
        sys.exit(1)

    if not check_only:
        # Re-read from disk so the summary reflects what was actually written.
        for name, (pattern, label) in TARGETS.items():
            written = pattern.search((root / name).read_text(encoding='utf-8')).group(2)
            if written != version:
                fail('%s still reads %s after writing' % (label, written))

    print('version %s is consistent across %d file(s)' % (version, len(TARGETS)))


if __name__ == '__main__':
    main()
