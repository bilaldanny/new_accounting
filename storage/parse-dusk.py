import json,sys
t=open(sys.argv[1],encoding='utf-8',errors='ignore').read()
i=t.index('{"tool"')
j=json.loads(t[i:t[i:].index('\nexit=')+i])
for k in ('failures','error_details'):
    for f in j.get(k,[]): print(k,f['test'][-70:],f['line'],f['message'][:700],'\n')
print(j['tests'],j['passed'])
