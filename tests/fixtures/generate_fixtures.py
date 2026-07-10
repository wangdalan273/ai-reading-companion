#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
生成 AI 伴读 用户流程模拟测试 所需素材：
  1. sample-qixue.epub       —— 合法 EPUB（中文，含人物/概念/论证/术语，覆盖 N3/N5/N6/N7/RAG）
  2. sample-qixue.pdf        —— 合法最小 PDF（用于导入 + PDF 占位流程）
  3. obsidian-vault/         —— Obsidian 笔记（frontmatter + [[双链]]，与书内容交叉）
  4. general-notes/          —— 通用笔记文件夹（非 Obsidian 连接器验证）

运行：python tests/fixtures/generate_fixtures.py
"""
import os, zipfile, datetime

HERE = os.path.dirname(os.path.abspath(__file__))

# ----------------------------------------------------------------------------
# 1) EPUB
# ----------------------------------------------------------------------------
EPUB_TITLE = "气血与养生浅说"
EPUB_AUTHOR = "佚名（示例）"

CHAPTERS = [
    ("ch1", "第一章 何谓气血",
     [
        "王大夫是镇上有名的中医生，这天李阿姨来问诊，开口便问：“大夫，常听人说‘气血不足’，到底什么是气血？”",
        "王大夫笑道：“气与血，是人身体里的两种根本物质。气主推动与温煦，血主濡养与滋润。气血充盈，则面色红润、四肢温暖；气血亏虚，则容易疲乏、头晕目眩。”",
        "“那经络和穴位又是做什么的？”李阿姨又问。王大夫解释：“经络是气血运行的通道，穴位则是通道上的关键节点。针灸与推拿，正是通过刺激穴位来调畅气血。”",
        "这一章先建立最基本的概念：气血、经络、穴位，是后续所有养生方法的地基。",
     ]),
    ("ch2", "第二章 气虚与血虚",
     [
        "张师傅最近总觉气短懒言，爬两层楼就喘。王大夫搭脉后说：“这是典型的气虚。”气虚的人，常感疲乏、易出汗、语声低微。”",
        "与气虚相对的是血虚。血虚多见面色萎黄、心悸失眠、手足发麻。李阿姨说自己月经后常头晕，便是血虚之象。",
        "气为血之帅，血为气之母。气虚日久，常会累及其他；血虚不愈，也难养其气。所以调理时往往气血同补。",
        "本章要点：识别气虚与血虚的不同表现，理解“气血互生”的关系。",
     ]),
    ("ch3", "第三章 养气养血之法",
     [
        "食养是最平和的办法。红枣、桂圆、当归、黄芪，都是常用的养血益气之品。王大夫常推荐用红枣黄芪煮水代茶。",
        "关于“吃当归能不能补气血”，门诊里常有争论。",
        "主张：当归是补血第一药，最能改善血虚。证据：古籍与多数临床经验显示，血虚者服当归后面色与精力改善。反驳：当归偏温，体质燥热或腹泻者单用反而上火，需配伍黄芪气血双补才稳妥。",
        "此外，规律作息与适度运动，比任何补药都重要。夜卧早起，使气血调畅，才是养生的根本。",
     ]),
    ("ch4", "第四章 常见误区",
     [
        "误区一：补得越多越好。王大夫提醒，过服补品反而壅滞脾胃，正所谓“虚不受补”。",
        "误区二：只补血不理气。气行则血行，若只补血而不健脾理气，补进去的血也难到达周身。",
        "术语小释：归脾汤，是益气补血、健脾养心的经典方，常用于气血两虚兼心悸失眠者。",
        "至此，读者应掌握气血的基本概念、虚证辨别、以及平补气血的原则。",
     ]),
]

def build_epub():
    out = os.path.join(HERE, "sample-qixue.epub")
    # ---- xhtml 章节 ----
    xhtml = {}
    for cid, title, paras in CHAPTERS:
        body = "\n".join(f"    <p>{p}</p>" for p in paras)
        xhtml[cid] = f"""<?xml version="1.0" encoding="utf-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="zh-CN" lang="zh-CN">
<head><meta charset="utf-8"/><title>{title}</title></head>
<body>
  <h1>{title}</h1>
{body}
</body>
</html>"""

    # ---- OPF ----
    manifest_items = "\n".join(
        f'    <item id="{cid}" href="OEBPS/text/{cid}.xhtml" media-type="application/xhtml+xml"/>'
        for cid, _, _ in CHAPTERS
    )
    spine_items = "\n".join(f'    <itemref idref="{cid}"/>' for cid, _, _ in CHAPTERS)
    opf = f"""<?xml version="1.0" encoding="utf-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="bookid">urn:uuid:sample-qixue-2026</dc:identifier>
    <dc:title>{EPUB_TITLE}</dc:title>
    <dc:creator>{EPUB_AUTHOR}</dc:creator>
    <dc:language>zh-CN</dc:language>
    <meta property="dcterms:modified">{datetime.date.today().isoformat()}T00:00:00Z</meta>
  </metadata>
  <manifest>
    <item id="ncx" href="OEBPS/toc.ncx" media-type="application/x-dtbncx+xml"/>
{manifest_items}
  </manifest>
  <spine toc="ncx">
{spine_items}
  </spine>
</package>"""

    # ---- NCX ----
    nav_points = "\n".join(
        f'    <navPoint id="nav-{cid}" playOrder="{i+1}"><navLabel><text>{title}</text></navLabel><content src="OEBPS/text/{cid}.xhtml"/></navPoint>'
        for i, (cid, title, _) in enumerate(CHAPTERS)
    )
    ncx = f"""<?xml version="1.0" encoding="utf-8"?>
<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">
  <head>
    <meta name="dtb:uid" content="urn:uuid:sample-qixue-2026"/>
    <meta name="dtb:depth" content="1"/>
    <meta name="dtb:totalPageCount" content="0"/>
    <meta name="dtb:maxPageNumber" content="0"/>
  </head>
  <docTitle><text>{EPUB_TITLE}</text></docTitle>
  <navMap>
{nav_points}
  </navMap>
</ncx>"""

    container = """<?xml version="1.0" encoding="utf-8"?>
<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
  <rootfiles>
    <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>
  </rootfiles>
</container>"""

    # ---- 写入 zip：mimetype 必须第一个且 STORED ----
    if os.path.exists(out):
        os.remove(out)
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("mimetype", "application/epub+zip", compress_type=zipfile.ZIP_STORED)
        z.writestr("META-INF/container.xml", container)
        z.writestr("OEBPS/content.opf", opf)
        z.writestr("OEBPS/toc.ncx", ncx)
        for cid, _, _ in CHAPTERS:
            z.writestr(f"OEBPS/text/{cid}.xhtml", xhtml[cid])
    print("EPUB ->", out, os.path.getsize(out), "bytes")


# ----------------------------------------------------------------------------
# 2) PDF（最小合法，2 页，ASCII 文本避免 CJK 字体缺失）
# ----------------------------------------------------------------------------
def _pdf_escaped(s):
    return s.replace("\\", r"\\").replace("(", r"\(").replace(")", r"\)")

def build_pdf():
    out = os.path.join(HERE, "sample-qixue.pdf")
    # 简单 2 页
    pages = [
        "AI Companion Test PDF",
        "Sample book for import flow (placeholder).",
        "Page 1 of 2.",
        "Qi and Blood sample (placeholder)",
        "Page 2 of 2.",
    ]
    objects = []
    # 1 catalog, 2 pages, 3 page1, 4 font, 5 content1, 6 page2, 7 content2
    objects.append(b"<< /Type /Catalog /Pages 2 0 R >>")
    objects.append(b"<< /Type /Pages /Kids [3 0 R 6 0 R] /Count 2 >>")
    objects.append(b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
                   b"/Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>")
    objects.append(b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>")
    stream1 = ("BT /F1 20 Tf 60 760 Td (" + _pdf_escaped(pages[0]) + ") Tj ET\n"
               "BT /F1 12 Tf 60 730 Td (" + _pdf_escaped(pages[1]) + ") Tj ET\n"
               "BT /F1 12 Tf 60 710 Td (" + _pdf_escaped(pages[2]) + ") Tj ET").encode("latin-1")
    objects.append(b"<< /Length " + str(len(stream1)).encode() + b" >>\nstream\n" + stream1 + b"\nendstream")
    objects.append(b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
                   b"/Resources << /Font << /F1 4 0 R >> >> /Contents 7 0 R >>")
    stream2 = ("BT /F1 16 Tf 60 760 Td (" + _pdf_escaped(pages[3]) + ") Tj ET\n"
               "BT /F1 12 Tf 60 730 Td (" + _pdf_escaped(pages[4]) + ") Tj ET").encode("latin-1")
    objects.append(b"<< /Length " + str(len(stream2)).encode() + b" >>\nstream\n" + stream2 + b"\nendstream")

    # 拼装 + 计算 xref 偏移
    out_bytes = bytearray(b"%PDF-1.4\n")
    offsets = [0] * (len(objects) + 1)
    for i, obj in enumerate(objects, start=1):
        offsets[i] = len(out_bytes)
        out_bytes += b"%d 0 obj\n" % i + obj + b"\nendobj\n"
    xref_pos = len(out_bytes)
    n = len(objects) + 1
    xref = bytearray(b"xref\n0 %d\n" % n)
    xref += b"0000000000 65535 f \n"
    for i in range(1, n):
        xref += b"%010d 00000 n \n" % offsets[i]
    xref += (b"trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n"
             % (n, xref_pos))
    out_bytes += xref
    with open(out, "wb") as f:
        f.write(out_bytes)
    print("PDF  ->", out, len(out_bytes), "bytes")


# ----------------------------------------------------------------------------
# 3) Obsidian vault（双链 + frontmatter）
# ----------------------------------------------------------------------------
def build_obsidian_vault():
    d = os.path.join(HERE, "obsidian-vault")
    os.makedirs(d, exist_ok=True)
    notes = {
        "气血.md": (
            "---\ntitle: 气血\ntags: [养生, 中医]\n---\n"
            "# 气血\n\n气血是中医对人体根本物质的概括。[[气]]主推动，[[血]]主濡养。"
            "与[[血虚]]常常相伴，调理讲究气血同补。详见[[经络与穴位]]。\n"
        ),
        "血虚.md": (
            "---\ntitle: 血虚\ntags: [养生, 中医]\n---\n"
            "# 血虚\n\n血虚表现为面色萎黄、心悸失眠。常配[[归脾汤]]益气补血，"
            "与[[气血]]关系最为密切。\n"
        ),
        "经络与穴位.md": (
            "---\ntitle: 经络与穴位\ntags: [中医, 针灸]\n---\n"
            "# 经络与穴位\n\n经络是[[气血]]运行的通道，穴位是通道上的节点。"
            "刺激穴位可调畅气血。\n"
        ),
        "养生周记.md": (
            "---\ntitle: 养生周记\ntags: [日记]\n---\n"
            "# 养生周记\n\n本周坚持红枣黄芪水，气色好转。复习了[[气血]]与[[血虚]]的概念，"
            "准备下周试[[归脾汤]]。\n"
        ),
    }
    for name, content in notes.items():
        with open(os.path.join(d, name), "w", encoding="utf-8") as f:
            f.write(content)
    print("Obsidian vault ->", d, len(notes), "notes")


# ----------------------------------------------------------------------------
# 4) 通用笔记文件夹（非 Obsidian 连接器）
# ----------------------------------------------------------------------------
def build_general_notes():
    d = os.path.join(HERE, "general-notes")
    os.makedirs(d, exist_ok=True)
    notes = {
        "睡眠与气血.md": (
            "# 睡眠与气血\n\n熬夜最伤气血。规律的睡眠是养气血的根本，"
            "比任何补药都重要。这点和书中讲的夜卧早起一致。\n"
        ),
        "饮食笔记.md": (
            "# 饮食笔记\n\n红枣、当归、黄芪都是养血益气之品。"
            "但过补反而壅滞，所谓虚不受补。\n"
        ),
    }
    for name, content in notes.items():
        with open(os.path.join(d, name), "w", encoding="utf-8") as f:
            f.write(content)
    print("General notes ->", d, len(notes), "notes")


if __name__ == "__main__":
    build_epub()
    build_pdf()
    build_obsidian_vault()
    build_general_notes()
    print("ALL FIXTURES READY")
