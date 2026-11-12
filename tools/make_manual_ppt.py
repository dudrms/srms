#!/usr/bin/env python3
"""사용자 매뉴얼(docs/사용자매뉴얼.md) 기반 롤별 PowerPoint 4종 생성.

사용: python tools/make_manual_ppt.py [--out docs/ppt]
- 화면 캡처는 docs/ppt/screens/*.png 를 사용한다(데모 서버에서 캡처, 밝은 테마).
- 의존: python-pptx, Pillow  (pip install python-pptx)
- 매뉴얼 내용이 바뀌면 이 파일의 문구를 고치고 다시 실행한다.
"""
from __future__ import annotations

import argparse
import io
import math
import re
import sys
from pathlib import Path

from lxml import etree
from PIL import Image, ImageChops
from pptx import Presentation
from pptx.dml.color import RGBColor
from pptx.enum.shapes import MSO_SHAPE
from pptx.enum.text import MSO_ANCHOR, PP_ALIGN
from pptx.oxml.ns import qn
from pptx.util import Emu, Inches, Pt

ROOT = Path(__file__).resolve().parent.parent
SCREENS = ROOT / "docs" / "ppt" / "screens"
FONT = "맑은 고딕"
SW, SH = 13.333, 7.5  # 슬라이드 크기(inch)
BODY_TOP, BODY_BOTTOM = 1.2, 6.95
VERSION_TEXT = "2026-11-12 기준 · 화면은 데모 데이터"
WARNINGS: list[str] = []

ROLES = {
    "req": dict(name="현업 사용자", code="REQUESTER", color="1D4ED8", nav="nav_req1", file="SRMS_사용자매뉴얼_현업사용자.pptx",
                menu="홈 · 요청 등록 · 요청 목록 · 처리함"),
    "biz": dict(name="현업 전산담당", code="BIZ_IT", color="0F766E", nav="nav_biz", file="SRMS_사용자매뉴얼_현업전산담당.pptx",
                menu="홈 · 요청 등록 · 요청 목록 · 처리함 · 리포트"),
    "it": dict(name="전산팀", code="IT_TEAM", color="6D28D9", nav="nav_it", file="SRMS_사용자매뉴얼_전산팀.pptx",
               menu="홈 · 요청 등록 · 요청 목록 · 처리함 · 작업일지 · 리포트"),
    "adm": dict(name="시스템관리자", code="SYS_ADMIN", color="B45309", nav="nav_admin", file="SRMS_사용자매뉴얼_시스템관리자.pptx",
                menu="홈 · 요청 등록 · 요청 목록 · 처리함 · 작업일지 · 리포트 · 관리"),
}


def rgb(h: str) -> RGBColor:
    return RGBColor.from_string(h)


# ----------------------------------------------------------------- 텍스트
def _fonts(run) -> None:
    run.font.name = FONT
    rPr = run._r.get_or_add_rPr()
    latin = rPr.find(qn("a:latin"))
    prev = latin
    for tag in ("a:ea", "a:cs"):
        el = rPr.find(qn(tag))
        if el is None:
            el = etree.Element(qn(tag))
            prev.addnext(el)
        el.set("typeface", FONT)
        prev = el


def _runs(p, text: str, size: float, color: str, bold: bool = False) -> None:
    """`**굵게**` 표기를 지원한다. 백틱(`버튼`)은 화면 표시어로 「 」 로 바꾼다."""
    text = re.sub(r"`([^`]+)`", r"「\1」", text)
    for i, part in enumerate(re.split(r"\*\*", text)):
        if part == "":
            continue
        r = p.add_run()
        r.text = part
        r.font.size = Pt(size)
        r.font.bold = bold or (i % 2 == 1)
        r.font.color.rgb = rgb(color)
        _fonts(r)


def _bullet(p, level: int, char: str = "•") -> None:
    pPr = p._p.get_or_add_pPr()
    pPr.set("marL", str(int(Inches(0.28 + 0.28 * level))))
    pPr.set("indent", str(int(-Inches(0.24))))
    bu = etree.SubElement(pPr, qn("a:buChar"))
    bu.set("char", char)


def text_w(s: str, size: float) -> float:
    w = 0.0
    for ch in s.replace("**", ""):
        o = ord(ch)
        if ch == " ":
            w += 0.30 * size
        elif o < 128:
            w += 0.54 * size
        else:
            w += 1.0 * size
    return w / 72.0


def est_lines(s: str, width_in: float, size: float) -> int:
    return max(1, math.ceil(text_w(s, size) * 1.10 / max(width_in, 0.1)))


def est_height(items, width_in: float, size: float, gap_pt: float = 5.0) -> float:
    h = 0.0
    for it in items:
        t, o = _norm(it)
        sz = o.get("size", size)
        w = width_in - (0.28 + 0.28 * o.get("level", 0) if o.get("bullet", True) else 0.0)
        h += est_lines(t, w, sz) * sz * 1.22 / 72.0 + (o.get("gap", gap_pt)) / 72.0
    return h


def _norm(it):
    if isinstance(it, str):
        return it, {}
    return it[0], it[1]


def fit_size(items, w, h, smin, smax) -> float:
    s = smax
    while s > smin and est_height(items, w, s) > h * 0.95:
        s -= 0.5
    return s


def add_text(slide, x, y, w, h, items, size=15, color="1F2937", bullet=True, anchor=MSO_ANCHOR.TOP, label="", fit=None) -> float:
    """items: str 또는 (str, {bullet, level, size, bold, color, gap, char}). fit=(최소, 최대) 이면 상자에 맞춰 글자 크기를 정한다."""
    if fit:
        size = fit_size(items, w, h, fit[0], fit[1])
    tb = slide.shapes.add_textbox(Inches(x), Inches(y), Inches(w), Inches(h))
    tf = tb.text_frame
    tf.word_wrap = True
    tf.vertical_anchor = anchor
    tf.margin_left = tf.margin_right = Inches(0.04)
    tf.margin_top = tf.margin_bottom = Inches(0.02)
    first = True
    for it in items:
        t, o = _norm(it)
        p = tf.paragraphs[0] if first else tf.add_paragraph()
        first = False
        p.space_after = Pt(o.get("gap", 5))
        p.line_spacing = 1.05
        _runs(p, t, o.get("size", size), o.get("color", color), o.get("bold", False))
        if o.get("bullet", bullet):
            _bullet(p, o.get("level", 0), o.get("char", "•"))
        if o.get("align") == "center":
            p.alignment = PP_ALIGN.CENTER
    need = est_height(items, w, size)
    if need > h + 0.05:
        WARNINGS.append(f"[넘침?] {label or items[0]!r:.40} 필요 {need:.2f}in > 상자 {h:.2f}in")
    return size


# ----------------------------------------------------------------- 표
def table_height(header, rows, widths, size) -> float:
    total = 0.0
    for cells in [header] + rows:
        row_h = 0.0
        for c_i, txt in enumerate(cells):
            lines = str(txt).split("\n")
            hh = sum(est_lines(line, widths[c_i] - 0.14, size) for line in lines) * size * 1.22 / 72.0 + 0.14
            row_h = max(row_h, hh)
        total += row_h
    return total


def add_table(slide, x, y, w, header, rows, col_w, size=None, color="1D4ED8", max_h=None, label="", smax=17.0) -> float:
    """size=None 이면 max_h 에 들어가는 가장 큰 글자 크기(최대 smax)를 쓴다."""
    n = len(rows) + 1
    tot = sum(col_w)
    widths = [w * c / tot for c in col_w]
    if size is None:
        size = smax
        while size > 11 and max_h is not None and table_height(header, rows, widths, size) > max_h * 0.97:
            size -= 0.5
    shape = slide.shapes.add_table(n, len(header), Inches(x), Inches(y), Inches(w), Inches(0.4 * n))
    tbl = shape.table
    tblPr = shape._element.graphic.graphicData.tbl.tblPr
    tblPr.set("firstRow", "1")
    tblPr.set("bandRow", "0")
    for i, cw in enumerate(widths):
        tbl.columns[i].width = Inches(cw)
    total_h = 0.0
    for r_i in range(n):
        cells = header if r_i == 0 else rows[r_i - 1]
        row_h = 0.0
        for c_i, txt in enumerate(cells):
            cell = tbl.cell(r_i, c_i)
            cell.margin_left = cell.margin_right = Inches(0.07)
            cell.margin_top = cell.margin_bottom = Inches(0.05)
            cell.vertical_anchor = MSO_ANCHOR.MIDDLE
            tf = cell.text_frame
            tf.word_wrap = True
            lines = str(txt).split("\n")
            for l_i, line in enumerate(lines):
                p = tf.paragraphs[0] if l_i == 0 else tf.add_paragraph()
                _runs(p, line, size, "FFFFFF" if r_i == 0 else "1F2937", bold=(r_i == 0))
            cell.fill.solid()
            cell.fill.fore_color.rgb = rgb(color) if r_i == 0 else rgb("EEF2F7" if r_i % 2 == 0 else "FFFFFF")
            hh = sum(est_lines(line, widths[c_i] - 0.14, size) for line in lines) * size * 1.22 / 72.0 + 0.14
            row_h = max(row_h, hh)
        tbl.rows[r_i].height = Inches(row_h)
        total_h += row_h
    if max_h is not None and total_h > max_h + 0.05:
        WARNINGS.append(f"[표 넘침?] {label} 필요 {total_h:.2f}in > {max_h:.2f}in")
    return total_h


# ----------------------------------------------------------------- 이미지
_cache: dict[str, Image.Image] = {}


def _load(name: str) -> Image.Image:
    if name not in _cache:
        path = SCREENS / f"{name}.png"
        if not path.exists():
            raise FileNotFoundError(path)
        im = Image.open(path).convert("RGB")
        w, h = im.size
        bg = Image.new("RGB", im.size, im.getpixel((w - 1, h - 1)))
        diff = ImageChops.difference(im, bg).point(lambda v: 255 if v > 14 else 0).convert("L")
        box = diff.getbbox()
        if box:
            pad = 6
            _cache[name] = (im, (max(0, box[0] - pad), max(0, box[1] - pad), min(w, box[2] + pad), min(h, box[3] + pad)))
        else:
            _cache[name] = (im, (0, 0, w, h))
    return _cache[name]


def shot(slide, name, box, crop=None, markers=(), caption=None, align="center", label="", valign="middle") -> None:
    """box=(x,y,w,h) inch 안에 비율을 유지해 넣는다. crop=(x0,y0,x1,y1) 원본 픽셀. markers=[(번호,x,y)] 원본 픽셀."""
    im, trim = _load(name)
    c = crop or trim
    c = (max(0, c[0]), max(0, c[1]), min(im.size[0], c[2]), min(im.size[1], c[3]))
    part = im.crop(c)
    bx, by, bw, bh = box
    cap_h = 0.3 if caption else 0.0
    scale = min(bw / part.size[0], (bh - cap_h) / part.size[1])
    dw, dh = part.size[0] * scale, part.size[1] * scale
    x0 = bx + ((bw - dw) / 2 if align == "center" else 0)
    y_top = by
    if valign == "middle":
        y_top = by + max(0.0, (bh - cap_h - dh) / 2)
    by = y_top
    buf = io.BytesIO()
    part.save(buf, "PNG", optimize=True)
    buf.seek(0)
    pic = slide.shapes.add_picture(buf, Inches(x0), Inches(by), Inches(dw), Inches(dh))
    pic.line.color.rgb = rgb("CBD5E1")
    pic.line.width = Pt(0.75)
    for n, mx, my in markers:
        px = x0 + (mx - c[0]) * scale
        py = by + (my - c[1]) * scale
        d = 0.30
        o = slide.shapes.add_shape(MSO_SHAPE.OVAL, Inches(px - d / 2), Inches(py - d / 2), Inches(d), Inches(d))
        o.fill.solid()
        o.fill.fore_color.rgb = rgb("DC2626")
        o.line.color.rgb = rgb("FFFFFF")
        o.line.width = Pt(1.25)
        tf = o.text_frame
        tf.margin_left = tf.margin_right = tf.margin_top = tf.margin_bottom = 0
        tf.vertical_anchor = MSO_ANCHOR.MIDDLE
        p = tf.paragraphs[0]
        p.alignment = PP_ALIGN.CENTER
        _runs(p, str(n), 11, "FFFFFF", bold=True)
    if caption:
        add_text(slide, bx, by + dh + 0.04, bw, 0.28, [(caption, {"bullet": False, "size": 11, "color": "64748B", "align": "center", "gap": 0})], label="caption")


# ----------------------------------------------------------------- 슬라이드 뼈대
class Deck:
    def __init__(self, key: str):
        self.key = key
        self.cfg = ROLES[key]
        self.prs = Presentation()
        self.prs.slide_width = Inches(SW)
        self.prs.slide_height = Inches(SH)
        self.n = 0

    # 공통 틀
    def slide(self, title: str, section: str = "", notes: str = ""):
        s = self.prs.slides.add_slide(self.prs.slide_layouts[6])
        self.n += 1
        bar = s.shapes.add_shape(MSO_SHAPE.RECTANGLE, 0, 0, Inches(SW), Inches(0.95))
        bar.fill.solid()
        bar.fill.fore_color.rgb = rgb(self.cfg["color"])
        bar.line.fill.background()
        add_text(s, 0.5, 0.17, 10.2, 0.62, [(title, {"bullet": False, "size": 27, "bold": True, "color": "FFFFFF", "gap": 0})], anchor=MSO_ANCHOR.MIDDLE, label=f"제목:{title}")
        if section:
            add_text(s, 10.4, 0.3, 2.5, 0.4, [(section, {"bullet": False, "size": 13, "color": "E5E7EB", "align": "center", "gap": 0})], anchor=MSO_ANCHOR.MIDDLE)
        add_text(s, 0.5, 7.08, 9.0, 0.3, [(f"SRMS 사용자 매뉴얼 · {self.cfg['name']} · {VERSION_TEXT}", {"bullet": False, "size": 10, "color": "94A3B8", "gap": 0})])
        add_text(s, 11.8, 7.08, 1.1, 0.3, [(str(self.n), {"bullet": False, "size": 10, "color": "94A3B8", "align": "center", "gap": 0})])
        if notes:
            s.notes_slide.notes_text_frame.text = notes
        return s

    def title_slide(self):
        s = self.prs.slides.add_slide(self.prs.slide_layouts[6])
        self.n += 1
        bg = s.shapes.add_shape(MSO_SHAPE.RECTANGLE, 0, 0, Inches(SW), Inches(SH))
        bg.fill.solid()
        bg.fill.fore_color.rgb = rgb(self.cfg["color"])
        bg.line.fill.background()
        add_text(s, 0.9, 2.0, 11.5, 1.0, [("SRMS 사용자 매뉴얼", {"bullet": False, "size": 44, "bold": True, "color": "FFFFFF", "gap": 0})])
        add_text(s, 0.9, 3.05, 11.5, 0.8, [(f"{self.cfg['name']} ({self.cfg['code']})", {"bullet": False, "size": 30, "color": "FFFFFF", "gap": 0})])
        add_text(s, 0.9, 4.2, 11.5, 1.4, [
            ("시스템 유지보수 요청 관리 시스템", {"bullet": False, "size": 18, "color": "E5E7EB"}),
            (VERSION_TEXT, {"bullet": False, "size": 15, "color": "E5E7EB"}),
            ("원본: docs/사용자매뉴얼.md (이 PPT 는 tools/make_manual_ppt.py 로 생성)", {"bullet": False, "size": 13, "color": "CBD5E1"}),
        ])
        s.notes_slide.notes_text_frame.text = "롤별 사용자 매뉴얼. 한 사람이 여러 롤을 가지면 해당 롤의 자료를 모두 보세요."

    # 유형별 슬라이드
    def bullets(self, title, items, image=None, section="", notes="", size=None, text_w_in=None, layout="side"):
        s = self.slide(title, section, notes)
        if image:
            tw = text_w_in or 5.3
            add_text(s, 0.5, BODY_TOP, tw, BODY_BOTTOM - BODY_TOP, items, label=title, fit=(13, 20))
            self._place(s, image, (0.5 + tw + 0.25, BODY_TOP, SW - 0.5 - (0.5 + tw + 0.25), BODY_BOTTOM - BODY_TOP), layout)
        else:
            add_text(s, 0.5, BODY_TOP, SW - 1.0, BODY_BOTTOM - BODY_TOP, items, label=title, fit=(13, 22))
        return s

    def _place(self, s, image, box, layout="side"):
        if isinstance(image, dict):
            image = [image]
        if len(image) == 1:
            d = dict(image[0])
            shot(s, d.pop("name"), box, **d)
        elif layout == "stack":
            gap = 0.2
            hi = (box[3] - gap * (len(image) - 1)) / len(image)
            for i, d in enumerate(image):
                d = dict(d)
                shot(s, d.pop("name"), (box[0], box[1] + i * (hi + gap), box[2], hi), **d)
        else:
            gap = 0.25
            wi = (box[2] - gap * (len(image) - 1)) / len(image)
            for i, d in enumerate(image):
                d = dict(d)
                shot(s, d.pop("name"), (box[0] + i * (wi + gap), box[1], wi, box[3]), **d)

    def table(self, title, header, rows, col_w, intro=None, notes_items=None, size=None, section="", notes="", image=None, table_w=None, layout="side"):
        s = self.slide(title, section, notes)
        y = BODY_TOP
        tw = table_w or (SW - 1.0)
        isz = 15
        if intro:
            hh = est_height(intro, tw, isz)
            add_text(s, 0.5, y, tw, hh + 0.05, intro, size=isz, label=title + " 소개")
            y += hh + 0.15
        foot_h = est_height(notes_items, tw, isz) + 0.25 if notes_items else 0.0
        th = add_table(s, 0.5, y, tw, header, rows, col_w, size=None, color=self.cfg["color"], max_h=BODY_BOTTOM - y - foot_h, label=title, smax=(17.0 if table_w is None else 15.0))
        if notes_items:
            add_text(s, 0.5, y + th + 0.15, tw, BODY_BOTTOM - (y + th + 0.15), notes_items, size=isz, label=title + " 주석")
        if image:
            self._place(s, image, (0.5 + tw + 0.25, BODY_TOP, SW - 0.5 - (0.5 + tw + 0.25), BODY_BOTTOM - BODY_TOP), layout)
        return s

    def images(self, title, image, items=None, section="", notes="", size=14, items_h=1.2):
        s = self.slide(title, section, notes)
        top = BODY_TOP
        if items:
            add_text(s, 0.5, top, SW - 1.0, items_h, items, size=size, label=title)
            top += items_h + 0.05
        self._place(s, image, (0.5, top, SW - 1.0, BODY_BOTTOM - top))
        return s

    def save(self, out_dir: Path) -> Path:
        out_dir.mkdir(parents=True, exist_ok=True)
        p = out_dir / self.cfg["file"]
        self.prs.save(p)
        return p


# ----------------------------------------------------------------- 공통 3장 (모든 롤)
def common(d: Deck, extra_nav: str = "") -> None:
    c = d.cfg
    s = d.slide("공통 ① 로그인과 기본 화면", "공통", "사용자매뉴얼 §1.1~1.3")
    shot(s, "login", (0.5, BODY_TOP, 3.9, 3.3), caption="로그인 화면")
    add_text(s, 4.7, BODY_TOP, 8.1, 3.6, [
        "접속 주소를 열고 **회사 · 로그인 ID · 비밀번호**를 입력해 로그인합니다.",
        "단일 회사 서버에서는 회사 선택이 없고, 사내 SSO 가 연결된 서버에는 사내 SSO 로그인 버튼이 있습니다.",
        "비밀번호를 **5회 틀리면 15분간 잠깁니다.** 잠시 뒤 다시 시도하거나 관리자에게 재설정을 요청하세요(재설정하면 바로 로그인 가능).",
        "**30분(기본) 동안 사용하지 않으면 자동 로그아웃**됩니다. 다시 로그인하세요.",
        "오른쪽 위: **어두운/밝은 테마**, **언어**(한국어·日本語·English·中文), **내 이름 메뉴**(내 정보·로그아웃). 테마는 계정에 저장됩니다.",
        "비밀번호 정책: 8자 이상, 영문+숫자 포함, 로그인 ID 와 같으면 안 됩니다.",
    ], label="공통① 본문", fit=(12, 17))
    shot(s, c["nav"], (0.5, 4.95, SW - 1.0, 0.75), caption=f"내 화면의 상단 메뉴 ({c['menu']}) — 권한에 따라 메뉴가 다르게 보입니다")
    add_text(s, 0.5, 6.05, SW - 1.0, 0.8, [
        ("사람이 입력한 제목·본문·의견은 언어를 바꿔도 번역되지 않고 그대로 보입니다. 메일은 내가 선택한 언어로 옵니다.", {"bullet": False, "size": 13, "color": "475569"}),
    ], label="공통① 주석")

    s = d.slide("공통 ② 요청의 상태와 상세 화면", "공통", "사용자매뉴얼 §1.4~1.5")
    th = add_table(s, 0.5, BODY_TOP, 5.7, ["상태 · 배지", "의미"], [
        ["임시저장", "작성 중(제출 전), 요청자만 볼 수 있음"],
        ["진행중", "제출되어 프로세스 단계를 따라 처리 중"],
        ["보완요청", "반려됨 → 요청자가 고쳐 다시 제출"],
        ["완료 / 취소", "마지막 단계까지 끝남 / 요청자가 취소"],
        ["지연", "완료요구일을 넘긴 진행 건"],
        ["일정 협의 중", "협의가 끝날 때까지 단계 처리 중단"],
        ["작업일지 · 분할", "전산팀이 사후 기록한 건 · SR 분할로 나뉼 건"],
    ], [1.7, 4.0], size=12.5, color=c["color"], max_h=3.6, label="공통② 표")
    add_text(s, 0.5, BODY_TOP + th + 0.2, 5.7, BODY_BOTTOM - (BODY_TOP + th + 0.2), [
        "**①** 프로세스 흐름: 단계·처리자·기한 (초록=완료, 파랑=진행중)",
        "**②** 처리 안내: 내가 처리자가 아니면 \"○○ 님이 처리합니다\" 안내만 표시",
        "**③** 일정 협의 · **④** 요약(완료요구일, 합의 완료일(계획))",
        "**⑤** 내용·첨부 · **⑥** 진행 이력(결재·반려·일정 변경·댓글)",
        "요청의 프로세스는 **제출 시점의 버전으로 고정**됩니다.",
    ], label="공통② 해설", fit=(11, 15))
    shot(s, "req_detail_s3", (6.4, BODY_TOP, 6.45, BODY_BOTTOM - BODY_TOP), markers=[
        (1, 40, 115), (2, 28, 352), (3, 28, 415), (4, 745, 352), (5, 28, 850), (6, 28, 1077)], label="공통② 화면")

    d.table("공통 ③ 메일 알림 규칙", ["상황", "메일을 받는 사람"], [
        ["내 차례가 된 단계(제출, 이전 단계 승인)", "해당 단계의 처리자"],
        ["반려 또는 보완요청", "다시 처리해야 하는 사람(이전 단계 처리자 또는 요청자)"],
        ["일정 변경 요청", "요청자(합의 여부 회신)"],
        ["일정 변경 반려/대안 제시", "요청한 작업자"],
        ["SR 분할", "요청자(분할 안내), 새 SR 현재 단계 처리자"],
        ["기한 임박/초과", "아직 처리하지 않은 현재 단계 처리자"],
        ["참조자가 설정된 단계", "참조자(관리자가 설정한 경우, 처리 버튼 없는 안내 메일)"],
    ], [4.6, 7.7], section="공통", notes="사용자매뉴얼 §1.6",
        notes_items=[
            "메일은 **내가 무언가를 해야 할 때만** 옵니다. 진행 결과가 궁금하면 화면(홈, 요청 상세)에서 확인하세요.",
            "내가 직접 한 처리, 일정 변경 **합의**, **작업일지 등록**에는 메일이 가지 않습니다.",
            "기한 독촉은 단계의 표준 기한이 다가오거나(기본 1영업일 전) 지난 단계에 대해 주기 작업이 보내며, 같은 건은 중복 발송하지 않고 일정 협의 중인 요청은 제외합니다.",
        ])
    return None


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default=str(ROOT / "docs" / "ppt"))
    ap.add_argument("--only", default="", help="req,biz,it,adm 중 일부만")
    a = ap.parse_args()
    sys.modules.setdefault("make_manual_ppt", sys.modules[__name__])  # 내용 모듈이 같은 모듈 인스턴스를 쓰도록
    import manual_ppt_content as content
    keys = [k for k in ROLES if not a.only or k in a.only.split(",")]
    for k in keys:
        d = Deck(k)
        content.BUILD[k](d, common)
        p = d.save(Path(a.out))
        print(f"{p.name}: {d.n}장")
    if WARNINGS:
        print("\n".join(WARNINGS))
        print(f"경고 {len(WARNINGS)}건")
    else:
        print("경고 없음")


if __name__ == "__main__":
    sys.path.insert(0, str(Path(__file__).resolve().parent))
    main()
