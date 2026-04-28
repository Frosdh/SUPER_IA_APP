from pathlib import Path
s=Path(r"lib/UI/views/OsmMapScreen.dart").read_text(encoding='utf-8')
pairs={'(':')','{':'}','[':']'}
closing={v:k for k,v in pairs.items()}
stack=[]
inq=None
i=0
while i < len(s):
    ch=s[i]
    if ch in ('"','\''):
        if inq is None:
            inq=ch
        elif inq==ch:
            # check for escaped quote
            backslashes=0
            j=i-1
            while j>=0 and s[j]=='\\':
                backslashes+=1
                j-=1
            if backslashes%2==0:
                inq=None
    else:
        if inq is None:
            if ch in pairs:
                stack.append((ch,i))
            elif ch in closing:
                if stack and stack[-1][0]==closing[ch]:
                    stack.pop()
                else:
                    line=s.count('\n',0,i)+1
                    lines=s.splitlines()
                    start=max(0,line-6)
                    end=min(len(lines),line+4)
                    print('UNMATCHED CLOSING',ch,'at idx',i,'line',line)
                    for ln in range(start,end):
                        print(f'{ln+1:5d}: {lines[ln]}')
                    break
    i+=1
else:
    if stack:
        print('UNMATCHED OPENINGS',len(stack))
        for k,v in stack[-10:]:
            line=s.count('\n',0,v)+1
            print('OPEN',k,'at idx',v,'line',line)
    else:
        print('ALL MATCHED')
