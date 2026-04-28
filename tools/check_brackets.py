import sys
from pathlib import Path
p=Path(r"lib/UI/views/OsmMapScreen.dart")
s=p.read_text(encoding='utf-8')
counts={'(':s.count('('),')':s.count(')'),'{':s.count('{'),'}':s.count('}'),'[':s.count('['),']':s.count(']')}
print('COUNTS:',counts)
pairs={'(':')','{':'}','[':']'}
closing={v:k for k,v in pairs.items()}
stack=[]
for i,ch in enumerate(s):
    if ch in pairs:
        stack.append((ch,i))
    elif ch in closing:
        if stack and stack[-1][0]==closing[ch]:
            stack.pop()
        else:
            print('UNMATCHED CLOSING',ch,'at',i,'context=>',s[max(0,i-40):i+40].replace('\n',' '))
            sys.exit(0)
if stack:
    print('UNMATCHED OPENINGS:',len(stack))
    for k,v in stack[-10:]:
        print('OPEN',k,'at',v,'context=>',s[max(0,v-40):v+40].replace('\n',' '))
else:
    print('ALL MATCHED')
