from pathlib import Path
s=Path(r"lib/UI/views/OsmMapScreen.dart").read_text(encoding='utf-8')
pairs={'(':')','{':'}','[':']'}
closing={v:k for k,v in pairs.items()}
stack=[]
in_string=None
for i,ch in enumerate(s):
    if ch in ('"','\''):
        if in_string is None:
            in_string=ch
        elif in_string==ch:
            # check escape
            bs=0
            j=i-1
            while j>=0 and s[j]=='\\':
                bs+=1; j-=1
            if bs%2==0:
                in_string=None
    else:
        if in_string is None:
            if ch in pairs:
                stack.append((ch,i))
            elif ch in closing:
                if stack and stack[-1][0]==closing[ch]:
                    stack.pop()
                else:
                    print('ERROR at idx',i,'char',ch)
                    print('Top of stack (last 8):')
                    for k,v in stack[-8:]:
                        line=s.count('\n',0,v)+1
                        print(k,'at idx',v,'line',line)
                    # print area
                    line=s.count('\n',0,i)+1
                    lines=s.splitlines()
                    start=max(0,line-8)
                    end=min(len(lines),line+4)
                    print('\nContext around error:')
                    for ln in range(start,end):
                        print(f'{ln+1:5d}: {lines[ln]}')
                    break
else:
    print('No unmatched closing found. Remaining stack size:',len(stack))
    for k,v in stack[-10:]:
        print(k,'at idx',v,'line', s.count('\n',0,v)+1)
