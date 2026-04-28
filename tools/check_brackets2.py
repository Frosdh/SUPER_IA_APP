from pathlib import Path
s=Path(r"lib/UI/views/OsmMapScreen.dart").read_text(encoding='utf-8')
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
            # compute line number
            line = s.count('\n',0,i)+1
            lines = s.splitlines()
            start = max(0,line-6)
            end = min(len(lines), line+4)
            print('UNMATCHED CLOSING',ch,'at index',i,'line',line)
            print('--- Surrounding lines: ---')
            for ln in range(start,end):
                print(f'{ln+1:5d}: {lines[ln]}')
            break
else:
    if stack:
        print('UNMATCHED OPENINGS COUNT',len(stack))
        for k,v in stack[-10:]:
            line = s.count('\n',0,v)+1
            print('OPEN',k,'at idx',v,'line',line)
    else:
        print('ALL MATCHED')
