import os
import re

root_dir = r"c:\Users\ulric\Desktop\projet web\Djoliba\Djolibasearch\djolibasearchV2"
templates_dir = os.path.join(root_dir, "templates")

for root, dirs, files in os.walk(templates_dir):
    for file in files:
        if file.endswith(".twig"):
            path = os.path.join(root, file)
            try:
                with open(path, "r", encoding="utf-8") as f:
                    content = f.read()
                    if "cl" in content or "Cl" in content:
                        for line_num, line in enumerate(content.splitlines(), 1):
                            if any(w in line.lower() for w in ["clé", "clef", "citation", "bibliogr"]):
                                print(f"{file}:{line_num}: {line.strip()}")
            except Exception as e:
                pass
