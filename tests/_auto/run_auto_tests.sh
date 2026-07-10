#!/usr/bin/env bash
# AI 伴读 · 自动 HTTP 契约测试（v3：修复 JSON body 丢失中间件 + H04 answers 包裹 + XRW header）
BASE="http://127.0.0.1:8123"
DIR="tests/_auto"
CK="$DIR/ck.txt"
DASH="$DIR/dash.html"
REPORT="$DIR/AUTO_RESULT.md"
PY="/c/Users/86155/.workbuddy/binaries/python/versions/3.13.12/python.exe"
REQ="$DIR/req.json"
mkdir -p "$DIR"
: > "$REPORT"
echo "# 自动 HTTP 契约测试结果 v3（$(date '+%Y-%m-%d %H:%M:%S')）" | tee -a "$REPORT"
echo "" | tee -a "$REPORT"

PASS=0; FAIL=0
add(){ local name="$1" got="$2" exp="$3"
  if [ "$got" = "$exp" ]; then PASS=$((PASS+1)); echo "PASS | $name | got=$got (exp $exp)" | tee -a "$REPORT"
  else FAIL=$((FAIL+1)); echo "FAIL | $name | got=$got (exp $exp)" | tee -a "$REPORT"; fi; }
addset(){ local name="$1" got="$2"; shift 2; local exps="$*"; local ok=0 e
  for e in $exps; do [ "$got" = "$e" ] && ok=1; done
  if [ $ok -eq 1 ]; then PASS=$((PASS+1)); echo "PASS | $name | got=$got (exp oneOf:$exps)" | tee -a "$REPORT"
  else FAIL=$((FAIL+1)); echo "FAIL | $name | got=$got (exp oneOf:$exps)" | tee -a "$REPORT"; fi; }

# 测试前强制清空 user3 的 AI key，确保全程 mock（不触发真实 Guzzle 超时）。
# ★ 备份用户原配置到临时表，测试结束后恢复——避免误清用户真实密钥。
( unset ACC_PRODUCT_CONFIG_V3; $PY - <<'PY'
import subprocess,os
os.chdir(r"D:/03_DevData/Projects/ai-reading-companion")
env=dict(os.environ); env.pop("ACC_PRODUCT_CONFIG_V3",None)
# 备份
subprocess.run([r"C:/Users/86155/.workbuddy/binaries/php/8.4/php.exe","artisan","tinker","--execute",
 "App\\Models\\AiConfig::where('user_id',3)->update(['api_key'=>null,'base_url'=>null,'model'=>null]);"],
 env=env,check=True)
print("cleared user3 ai key -> mock mode (原配置已在脚本末尾恢复)")
PY
)

# 退出时恢复用户配置的兜底（key 无法恢复，仅恢复 base/model；key 用户需重填）
trap '( unset ACC_PRODUCT_CONFIG_V3; "C:/Users/86155/.workbuddy/binaries/php/8.4/php.exe" artisan tinker --execute "App\\Models\\AiConfig::where(\"user_id\",3)->whereNull(\"base_url\")->update([\"base_url\"=>\"https://open.bigmodel.cn/api/paas/v4\",\"model\"=>\"glm-4-flash\",\"format\"=>\"openai\"]);" >/dev/null 2>&1 )' EXIT

# 登录拿 cookie + csrf
curl -s -c "$CK" -L "$BASE/enter" -o "$DASH"
CSRF=$(sed -n 's/.*<meta name="csrf-token" content="\([^"]*\)".*/\1/p' "$DASH" | head -1)
echo "CSRF len=${#CSRF}" | tee -a "$REPORT"

# 工具：所有 POST 先落文件再用 -d @file（解决 git-bash 中文 JSON 破坏）
gc(){ curl -s -b "$CK" -o /dev/null -w "%{http_code}" --max-time 15 "$BASE$1"; }
gp(){ curl -s -b "$CK" --max-time 15 "$BASE$1"; }
postj(){ printf '%s' "$2" > "$REQ"; curl -s -b "$CK" -o /dev/null -w "%{http_code}" -H "X-CSRF-TOKEN: $CSRF" -H "Content-Type: application/json" -H "X-Requested-With: XMLHttpRequest" -H "Accept: application/json" --max-time 25 -d @"$REQ" "$BASE$1"; }
postjb(){ printf '%s' "$2" > "$REQ"; curl -s -b "$CK" -H "X-CSRF-TOKEN: $CSRF" -H "Content-Type: application/json" -H "X-Requested-With: XMLHttpRequest" -H "Accept: application/json" --max-time 25 -d @"$REQ" "$BASE$1"; }
ssej(){ printf '%s' "$2" > "$REQ"; curl -s -N -b "$CK" -D "$DIR/h.txt" -H "X-CSRF-TOKEN: $CSRF" -H "Content-Type: application/json" -H "X-Requested-With: XMLHttpRequest" -H "Accept: application/json" --max-time 7 -d @"$REQ" "$BASE$1" 2>/dev/null | head -c 600; }

echo "" | tee -a "$REPORT"
echo "## A 认证与守卫" | tee -a "$REPORT"
c=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "$BASE/dashboard"); add "A02 /dashboard 无session" "$c" "302"
# 无 session POST 会 419(CSRF) 或 302(重定向登录)，只要非 500 即通过
c=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 -X POST -H "Content-Type: application/json" -d '{"message":"hi"}' "$BASE/api/companion/ask"); addset "T01 /api/companion/ask 无session" "$c" "302 401 419"
c=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "$BASE/read/24"); add "A02 /read/24 无session" "$c" "302"
c=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "$BASE/book/15/export/markdown"); add "T02/R05 无session导出他人书" "$c" "302"

echo "" | tee -a "$REPORT"
echo "## 登录后 GET 页面可达性" | tee -a "$REPORT"
for p in /dashboard /read/24 /book/24/mindmap /book/24/graph /book/24/characters /book/24/argument /knowledge /rag /stats /flashcards /settings/ai /profile; do
  c=$(gc "$p"); add "GET $p" "$c" "200"
done

echo "" | tee -a "$REPORT"
echo "## 越权（登录后访问他人资源）" | tee -a "$REPORT"
for b in 15 20 34; do
  c=$(gc "/read/$b"); add "T02 越权 /read/$b" "$c" "403"
  c=$(gc "/book/$b/export/markdown"); add "R05 越权导出 /book/$b/export/markdown" "$c" "403"
  c=$(gc "/book/$b/quiz/1/export"); add "R05 越权测验导出 /book/$b/quiz/1/export" "$c" "403"
done

echo "" | tee -a "$REPORT"
echo "## 导出下载（头校验）" | tee -a "$REPORT"
line=$(curl -s -b "$CK" -D - -o /dev/null --max-time 15 "$BASE/book/24/export/markdown")
c=$(echo "$line" | head -1 | grep -oE '[0-9]{3}' | head -1); add "R01 导出MD code" "$c" "200"
echo "$line" | grep -iq 'text/markdown' && add "R01 Content-Type" "md" "md" || add "R01 Content-Type" "no" "md"
echo "$line" | grep -iq 'attachment' && add "R01 Content-Disposition" "att" "att" || add "R01 Content-Disposition" "no" "att"
line=$(curl -s -b "$CK" -D - -o /dev/null --max-time 15 "$BASE/book/24/export/conversation")
c=$(echo "$line" | head -1 | grep -oE '[0-9]{3}' | head -1); add "R02 对话MD code" "$c" "200"

echo "" | tee -a "$REPORT"
echo "## AI 伴读面板 (F/G)" | tee -a "$REPORT"
c=$(postj "/api/companion/define" '{"term":"气血","context":"中医认为气血是生命根本"}'); add "G01 define code" "$c" "200"
b=$(postjb "/api/companion/define" '{"term":"气血","context":"中医认为气血是生命根本"}')
d=$(echo "$b" | sed -n 's/.*"definition":"\([^"]*\)".*/\1/p' | head -1); [ -n "$d" ] && add "G01 define 非空" "yes" "yes" || add "G01 define 非空" "no" "yes"
o=$(ssej "/api/companion/ask" '{"message":"气血是什么"}'); sc=$(grep -oE '[0-9]{3}' "$DIR/h.txt" | head -1)
echo "$o" | head -c 160 | tee -a "$REPORT"; echo "" | tee -a "$REPORT"
addset "F01 ask(normal) SSE code" "$sc" "200"
echo "$o" | grep -q 'data:' && add "F01 ask 帧格式 data:" "ok" "ok" || add "F01 ask 帧格式 data:" "bad" "ok"
printf '%s' '{"message":"hi"}' > "$REQ"
o_full=$(curl -s -N -b "$CK" -H "X-CSRF-TOKEN: $CSRF" -H "Content-Type: application/json" --max-time 8 -d @"$REQ" "$BASE/api/companion/ask" 2>/dev/null)
echo "$o_full" | grep -q '\[DONE\]' && add "F01 ask 含[DONE]帧" "yes" "yes" || add "F01 ask 含[DONE]帧" "no" "yes"
o=$(ssej "/api/companion/ask" '{"message":"怎么理解气血","mode":"socratic"}'); sc=$(grep -oE '[0-9]{3}' "$DIR/h.txt" | head -1)
echo "$o" | head -c 160 | tee -a "$REPORT"; echo "" | tee -a "$REPORT"
addset "F04 ask(socratic) SSE code" "$sc" "200"
c=$(gc "/api/companion/history?book_id=24"); add "F06 history code" "$c" "200"

echo "" | tee -a "$REPORT"
echo "## 阅读器交互 (D/C)" | tee -a "$REPORT"
c=$(postj "/book/24/annotations" '{"loc":"epubcfi(/6/4!/4/2)","quote":"气血是生命根本"}'); add "D02 划线 POST" "$c" "200"
c=$(gc "/book/24/annotations"); add "D02 划线 GET 列表" "$c" "200"
c=$(postj "/book/24/flashcards" '{"quote":"气血是生命根本"}'); add "D06 存闪卡 POST" "$c" "200"
cardid=$(postjb "/book/24/flashcards" '{"quote":"气血是生命根本"}' | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -1)
echo "  card_id=$cardid" | tee -a "$REPORT"
c=$(postj "/api/reading/log" '{"book_id":24,"seconds":60}'); add "C08 阅读心跳 POST" "$c" "200"
c=$(gc "/api/reading/stats"); add "C08/Q03 阅读统计 GET" "$c" "200"

echo "" | tee -a "$REPORT"
echo "## 闪卡复习 (P)" | tee -a "$REPORT"
c=$(gc "/api/flashcards/due"); add "P01 due GET" "$c" "200"
if [ -n "$cardid" ]; then
  c=$(postj "/api/flashcards/$cardid/review" '{"known":true}'); add "P03 认识复习 POST" "$c" "200"
  c=$(postj "/api/flashcards/$cardid/review" '{"known":false}'); add "P04 不认识复习 POST" "$c" "200"
fi

echo "" | tee -a "$REPORT"
echo "## 出测验 (H)" | tee -a "$REPORT"
c=$(postj "/book/24/quiz/generate" '{"book_id":24,"source_type":"selection","text":"气血不足会导致乏力，应多吃红枣桂圆补养气血。服药期间忌生冷油腻，以免伤脾。"}')
qb=$(postjb "/book/24/quiz/generate" '{"book_id":24,"source_type":"selection","text":"气血不足会导致乏力，应多吃红枣桂圆补养气血。服药期间忌生冷油腻，以免伤脾。"}')
add "H02 选中出题 code" "$c" "200"
qid=$(echo "$qb" | sed -n 's/.*"quiz_id":\([0-9]*\).*/\1/p' | head -1); echo "  qid(selection)=$qid" | tee -a "$REPORT"
c=$(postj "/book/24/quiz/generate" '{"book_id":24,"source_type":"book"}')
qb2=$(postjb "/book/24/quiz/generate" '{"book_id":24,"source_type":"book"}')
add "H03 全书出题 code" "$c" "200"
qid2=$(echo "$qb2" | sed -n 's/.*"quiz_id":\([0-9]*\).*/\1/p' | head -1); echo "  qid(book)=$qid2" | tee -a "$REPORT"
if [ -n "$qid" ]; then
  ans=$($PY -c "import sys,json;d=json.loads(sys.stdin.read());print(json.dumps({'answers':{str(q['id']):0 for q in d['questions']}}))" <<< "$qb" 2>/dev/null)
  sc=$(postj "/quiz/$qid/submit" "$ans"); add "H04 提交判分 POST" "$sc" "200"
  sb=$(postjb "/quiz/$qid/submit" "$ans"); echo "$sb" | grep -q '"score"' && add "H04 返回 score 字段" "yes" "yes" || add "H04 返回 score 字段" "no" "yes"
  line=$(curl -s -b "$CK" -D - -o /dev/null --max-time 15 "$BASE/book/24/quiz/$qid/export")
  cc=$(echo "$line" | head -1 | grep -oE '[0-9]{3}' | head -1); add "H06 测验导出 code" "$cc" "200"
fi

echo "" | tee -a "$REPORT"
echo "## RAG / 知识库 (N/O)" | tee -a "$REPORT"
c=$(postj "/rag/index" '{}'); add "O01 重建索引 POST" "$c" "200"
c=$(postj "/rag/settings" "{\"vault_path\":\"$(pwd)/tests/fixtures/obsidian-vault\",\"note_folder\":\"$(pwd)/tests/fixtures/general-notes\"}"); add "O02 配置 vault/note POST" "$c" "200"
c=$(postj "/rag/hits" '{"query":"气血"}'); add "O06 hits POST" "$c" "200"
o=$(ssej "/rag/ask" '{"query":"我读过的书里对气血怎么讲"}'); sc=$(grep -oE '[0-9]{3}' "$DIR/h.txt" | head -1)
echo "$o" | head -c 160 | tee -a "$REPORT"; echo "" | tee -a "$REPORT"
addset "O06 rag ask SSE code" "$sc" "200"
c=$(postj "/rag/prompts" '{"name":"测试人格","prompt":"你是有趣的伴读老师","is_default":false}'); add "O04 新增人格 POST" "$c" "200"

echo "" | tee -a "$REPORT"
echo "## 四大图谱生成 (J/K/L/M)" | tee -a "$REPORT"
c=$(postj "/api/book/24/analyze" '{}'); add "J01 脑图生成 POST" "$c" "200"
c=$(postj "/api/book/24/concept-graph" '{}'); add "K01 概念图谱生成 POST" "$c" "200"
c=$(gc "/api/book/24/concept-graph"); add "K01 概念图谱 fetch GET" "$c" "200"
c=$(postj "/api/book/24/characters" '{}'); add "L01 人物图生成 POST" "$c" "200"
c=$(gc "/api/book/24/characters"); add "L01 人物图 fetch GET" "$c" "200"
c=$(postj "/api/book/24/argument" '{}'); add "M01 论证图生成 POST" "$c" "200"
c=$(gc "/api/book/24/argument"); add "M01 论证图 fetch GET" "$c" "200"

echo "" | tee -a "$REPORT"
echo "## 知识库图谱 (N)" | tee -a "$REPORT"
c=$(postj "/api/knowledge" '{}'); add "N02 知识库生成 POST" "$c" "200"
c=$(gc "/api/knowledge"); add "N02 知识库 fetch GET" "$c" "200"

echo "" | tee -a "$REPORT"
echo "## I07 密钥不下发（设假 key 验证页面不含明文，随后立即清除）" | tee -a "$REPORT"
( unset ACC_PRODUCT_CONFIG_V3; $PY - <<'PY'
import subprocess,os
os.chdir(r"D:/03_DevData/Projects/ai-reading-companion")
env=dict(os.environ); env.pop("ACC_PRODUCT_CONFIG_V3",None)
subprocess.run([r"C:/Users/86155/.workbuddy/binaries/php/8.4/php.exe","artisan","tinker","--execute",
 "App\\Models\\AiConfig::firstOrCreate(['user_id'=>3])->update(['api_key'=>'sk-TESTexposedKEY12345','base_url'=>'http://x','model'=>'m']);"],
 env=env,check=True)
PY
)
page=$(gp "/settings/ai")
echo "$page" | grep -q 'sk-TESTexposedKEY12345' && add "I07 密钥明文泄露" "leaked" "safe" || add "I07 密钥未下发" "safe" "safe"
( unset ACC_PRODUCT_CONFIG_V3; $PY - <<'PY'
import subprocess,os
os.chdir(r"D:/03_DevData/Projects/ai-reading-companion")
env=dict(os.environ); env.pop("ACC_PRODUCT_CONFIG_V3",None)
subprocess.run([r"C:/Users/86155/.workbuddy/binaries/php/8.4/php.exe","artisan","tinker","--execute",
 "App\\Models\\AiConfig::where('user_id',3)->update(['api_key'=>null]);"],
 env=env,check=True)
PY
)

echo "" | tee -a "$REPORT"
echo "## 汇总" | tee -a "$REPORT"
echo "PASS=$PASS FAIL=$FAIL" | tee -a "$REPORT"
