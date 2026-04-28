from pathlib import Path
s=Path('lib/UI/views/OsmMapScreen.dart').read_text(encoding='utf-8')
idx=28528
print('Context at idx',idx)
print(repr(s[idx-40:idx+40]))
print('\nLine number:', s.count('\n',0,idx)+1)
lines=s.splitlines()
ln=s.count('\n',0,idx)+1
start=max(1,ln-4)
end=min(len(lines),ln+4)
for i in range(start,end+1):
    print(i, lines[i-1])
