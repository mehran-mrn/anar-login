"""Check the description limit after WordPress merges custom readme sections."""

from pathlib import Path
import re


def description_word_count(readme: str) -> int:
    expected = {'description', 'installation', 'faq', 'screenshots', 'changelog', 'upgrade_notice'}
    aliases = {'frequently_asked_questions': 'faq', 'change_log': 'changelog', 'screenshot': 'screenshots'}
    parts = re.split(r'(?m)^== (.+?) ==\s*$', readme)
    description = []
    for index in range(1, len(parts), 2):
        name = parts[index].strip().lower().replace(' ', '_')
        name = aliases.get(name, name)
        if name == 'description' or name not in expected:
            description.append(parts[index] + '\n' + parts[index + 1])
    return len('\n'.join(description).split())


def validate(readme: str) -> int:
    words = description_word_count(readme)
    if words >= 2500:
        raise ValueError(f'WordPress description including custom sections has {words} words; keep it below 2500.')
    return words


if __name__ == '__main__':
    root = Path(__file__).resolve().parents[1]
    print(f'WordPress merged description: {validate((root / "readme.txt").read_text(encoding="utf-8-sig"))} words; no truncation.')
