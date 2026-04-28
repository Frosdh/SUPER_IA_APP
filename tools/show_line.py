from pathlib import Path
p=Path('lib/UI/views/OsmMapScreen.dart')
lines=p.read_text(encoding='utf-8').splitlines()
ln=820
print('Line',ln,':',lines[ln-1])
for i,ch in enumerate(lines[ln-1],start=1):
    print(f'{i:03d} {repr(ch)}')
