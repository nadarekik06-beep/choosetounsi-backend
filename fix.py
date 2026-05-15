import re

path = 'app/Http/Controllers/Api/Seller/SellerAIController.php'
with open(path, encoding='utf-8') as f:
    content = f.read()

def fix_string(m):
    inner = m.group(1)
    if "'" in inner:
        return '"' + inner + '"'
    return m.group(0)

fixed = re.sub(r"'([^'\n]*'[^'\n]*)'", fix_string, content)

with open(path, 'w', encoding='utf-8') as f:
    f.write(fixed)

print('Done')