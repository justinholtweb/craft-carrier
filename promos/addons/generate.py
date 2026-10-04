"""Writes the Plugin Store promo deck for every Carrier add-on.

    python3 generate.py             # all eight
    python3 generate.py gls boxnow  # just these

Each add-on gets a self-contained `promos/` directory in its own repo — slides.html, build.sh,
fonts and icons — so it builds with its own ./build.sh and nothing here needs to exist at
release time. This file and its data are the source; edit these, not the generated decks.

Two inputs:

- carriers.json — every registered carrier's capabilities, services and connection fields, grouped
  by add-on package and dumped from the carriers themselves by tests/tools/dump-carriers.php. The
  "what it does", "connecting" and "services" slides are drawn from it, so a slide cannot claim a
  feature the carrier does not declare. Regenerate it whenever a carrier's capabilities(),
  services() or settingsFields() change.
- decks.json — the copy that has to be written by a person: the tagline, the carrier traps the
  add-on handles, and what could not be verified without a live account.

Carrier's add-on icons carry a generic mark (a truck, a pallet, a pin), never the carrier's logo,
and these decks follow suit: no carrier branding anywhere, only the add-on's corner colour.
"""
import html, json, os, shutil, sys

HERE = os.path.dirname(os.path.abspath(__file__))
PROMOS = os.path.dirname(HERE)                       # craft-carrier/promos
SITES = os.path.dirname(os.path.dirname(PROMOS))     # ~/Sites

FAMILY = ['ups', 'fedex', 'usps', 'freight', 'dpd', 'gls', 'boxnow', 'posts']
FAMILY_NAMES = {'ups': 'UPS', 'fedex': 'FedEx', 'usps': 'USPS', 'freight': 'LTL Freight', 'dpd': 'DPD',
                'gls': 'GLS', 'boxnow': 'BOX NOW', 'posts': 'Posts'}

# Feature::all() order and Feature::label() wording, so the slides say what the control panel says.
FEATURES = [
    ('rates', 'Live rates'), ('labels', 'Labels'), ('cancel', 'Void labels'), ('reprint', 'Reprint'),
    ('tracking', 'Tracking'), ('points', 'Pickup points'), ('pickups', 'Collections'),
    ('closeDay', 'End-of-day close'), ('returns', 'Return labels'), ('cod', 'Cash on delivery'),
    ('addressValidation', 'Address validation'), ('webhooks', 'Tracking webhooks'),
    ('international', 'International'), ('multiPiece', 'Multi-piece'), ('freight', 'Freight (LTL)'),
]
FORMATS = {'pdf-a4': 'PDF, A4 sheet', 'pdf-a6': 'PDF, A6 thermal', 'pdf-4x6': 'PDF, 4×6 in thermal',
           'pdf-letter': 'PDF, US Letter sheet', 'zpl': 'ZPL (Zebra thermal)', 'png': 'PNG image, 4×6 in'}
POINT_MODES = {'list': 'synced daily, searched locally', 'search': 'searched live near the customer',
               'manual': 'imported from CSV by you'}
POINT_TYPES = {'locker': 'lockers', 'shop': 'parcel shops', 'postOffice': 'post offices', 'store': 'stores'}

e = lambda s: html.escape(str(s), quote=True)


def css(colour, text_colour):
    return '''
  * { margin: 0; padding: 0; box-sizing: border-box; }
  :root {
    --accent: #5C6DF2; --accent-light: #8A97F7; --gold: #ffd166; --off-white: #f0f0f5;
    --addon: %s; --addon-text: %s;
  }
  html { background: #000; }
  body { font-family: 'Inter', -apple-system, sans-serif; color: var(--off-white); -webkit-font-smoothing: antialiased; }
  .slide {
    position: relative; width: 1920px; height: 1080px; overflow: hidden;
    background:
      radial-gradient(ellipse at 85%% 15%%, rgba(92, 109, 242, 0.22) 0%%, transparent 40%%),
      radial-gradient(ellipse at 65%% 5%%, rgba(138, 151, 247, 0.14) 0%%, transparent 35%%),
      radial-gradient(ellipse at 15%% 85%%, rgba(255, 209, 102, 0.07) 0%%, transparent 40%%),
      linear-gradient(135deg, #0f0f1a 0%%, #11132a 30%%, #141733 60%%, #0f0f1a 100%%);
  }
  .slide::after {
    content: ''; position: absolute; top: -18%%; left: -12%%; width: 1180px; height: 1180px;
    background: url('assets/watermark.svg') no-repeat center / contain; opacity: 0.02; pointer-events: none;
  }
  .slide::before {
    content: ''; position: absolute; inset: 0 0 auto 0; height: 6px; z-index: 5;
    background: linear-gradient(90deg, var(--accent), var(--accent-light), var(--gold), var(--accent));
  }
  .cover::after { display: none; }
  .inner { position: relative; z-index: 2; height: 100%%; padding: 118px 130px; display: flex; flex-direction: column; justify-content: center; }
  .eyebrow {
    display: flex; align-items: center; gap: 18px; font-size: 20px; font-weight: 500;
    letter-spacing: 0.32em; text-transform: uppercase; color: rgba(240, 240, 245, 0.62); margin-bottom: 30px;
  }
  .eyebrow .line { width: 58px; height: 3px; border-radius: 2px; background: var(--addon-text); }
  h1, h2 { font-family: 'Jersey 20', sans-serif; font-weight: 400; line-height: 0.9; letter-spacing: 0.005em; }
  h2 { font-size: 100px; margin-bottom: 24px; }
  .gradient-text {
    background: linear-gradient(100deg, #5C6DF2 0%%, #8A97F7 38%%, #ffd166 68%%, #5C6DF2 100%%);
    -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
  }
  .lede { font-size: 29px; line-height: 1.5; color: rgba(240, 240, 245, 0.72); max-width: 62ch; }
  .lede b { color: var(--off-white); font-weight: 600; }
  .mono { font-family: 'JetBrains Mono', ui-monospace, 'SF Mono', Menlo, monospace; }
  code { font-family: 'JetBrains Mono', ui-monospace, Menlo, monospace; font-size: 0.86em; color: #d4d9ff; background: rgba(255, 255, 255, 0.06); padding: 1px 7px; border-radius: 5px; }
  .footer {
    position: absolute; left: 130px; bottom: 58px; z-index: 3; display: flex; align-items: center; gap: 18px;
    font-size: 22px; letter-spacing: 0.06em; color: rgba(240, 240, 245, 0.4);
  }
  .footer img { width: 40px; height: 40px; }
  .footer b { color: rgba(240, 240, 245, 0.72); font-weight: 600; }
  .panel { background: rgba(26, 26, 46, 0.72); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 22px; }

  /* cover */
  .cover .inner { flex-direction: row; align-items: center; justify-content: space-between; gap: 80px; }
  .cover h1 { font-size: 150px; margin-bottom: 40px; }
  .cover h1 span { display: block; font-size: 96px; color: rgba(240, 240, 245, 0.5); -webkit-text-fill-color: rgba(240, 240, 245, 0.5); margin-bottom: 8px; }
  .cover .tagline { font-family: 'Jersey 20', sans-serif; font-size: 54px; line-height: 1.05; margin-bottom: 34px; max-width: 22ch; }
  .cover .lede { font-size: 28px; max-width: 38ch; }
  .cover .mark { flex: 0 0 auto; width: 440px; height: 440px; filter: drop-shadow(14px 14px 0 rgba(0, 0, 0, 0.35)); }
  .cover .mark img { width: 100%%; height: 100%%; display: block; }
  .badges { display: flex; gap: 16px; margin-top: 46px; flex-wrap: wrap; }
  .badge {
    display: inline-flex; align-items: center; gap: 12px; padding: 14px 26px; font-size: 22px; font-weight: 500;
    color: rgba(240, 240, 245, 0.85); border: 1px solid rgba(255, 255, 255, 0.12); background: rgba(255, 255, 255, 0.05); border-radius: 999px;
  }
  .badge .dot { width: 10px; height: 10px; border-radius: 50%%; background: var(--addon-text); }

  /* what it does */
  .does .inner, .services .inner, .family .inner { justify-content: flex-start; padding-top: 92px; }
  .does h2, .services h2 { font-size: 88px; margin-bottom: 18px; }
  .does .lede, .services .lede { font-size: 26px; }
  .feats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px 18px; margin-top: 36px; }
  .feat { display: flex; align-items: center; gap: 16px; padding: 20px 24px; border-radius: 14px; font-size: 25px; font-weight: 500;
          background: rgba(37, 37, 64, 0.6); border: 1px solid rgba(255, 255, 255, 0.08); }
  .feat .ic { flex: 0 0 auto; width: 30px; height: 30px; border-radius: 50%%; display: grid; place-items: center; font-size: 18px; font-weight: 700; }
  .feat.on .ic { background: rgba(74, 222, 128, 0.16); color: #4ade80; }
  .feat.off { color: rgba(240, 240, 245, 0.26); background: rgba(37, 37, 64, 0.25); border-style: dashed; }
  .feat.off .ic { background: rgba(255, 255, 255, 0.04); color: rgba(240, 240, 245, 0.26); }
  .matrix { margin-top: 30px; padding: 12px 30px; }
  .matrix table { border-collapse: collapse; width: 100%%; }
  .matrix th { font-size: 19px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: rgba(240, 240, 245, 0.55); text-align: center; padding: 14px 12px; }
  .matrix th:first-child { text-align: left; }
  .matrix td { font-size: 24px; padding: 9px 12px; border-top: 1px solid rgba(255, 255, 255, 0.06); text-align: center; }
  .matrix td:first-child { text-align: left; color: var(--off-white); font-weight: 500; }
  .yes { color: #4ade80; font-weight: 700; }
  .no { color: rgba(240, 240, 245, 0.2); }
  .legend { display: flex; gap: 14px 30px; flex-wrap: wrap; margin-top: 24px; font-size: 21px; color: rgba(240, 240, 245, 0.55); }
  .legend b { color: rgba(240, 240, 245, 0.85); font-weight: 600; }

  /* connecting */
  .split { display: flex; gap: 90px; align-items: center; }
  .split .copy { flex: 0 0 680px; }
  .form { flex: 1 1 auto; padding: 38px 44px; }
  .form .title { display: flex; align-items: center; gap: 16px; font-size: 26px; font-weight: 600; margin-bottom: 26px; }
  .form .title img { width: 44px; height: 44px; }
  .form .field { margin-bottom: 15px; min-width: 0; }
  .form label { display: block; font-size: 18px; font-weight: 600; color: rgba(240, 240, 245, 0.7); margin-bottom: 7px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .form label i { color: #f87171; font-style: normal; margin-left: 4px; }
  .form .input { height: 44px; line-height: 42px; border-radius: 8px; background: rgba(0, 0, 0, 0.28); border: 1px solid rgba(255, 255, 255, 0.1); padding: 0 16px; font-size: 19px; color: rgba(240, 240, 245, 0.35); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .form .input.secret { letter-spacing: 0.3em; color: rgba(240, 240, 245, 0.55); }
  .form .toggle { width: 52px; height: 30px; border-radius: 999px; background: rgba(255, 255, 255, 0.12); position: relative; margin-top: 7px; }
  .form .toggle::after { content: ''; position: absolute; left: 4px; top: 4px; width: 22px; height: 22px; border-radius: 50%%; background: rgba(240, 240, 245, 0.6); }
  .form .cols { display: grid; grid-template-columns: 1fr 1fr; column-gap: 22px; }
  .form .more { font-size: 18px; color: rgba(240, 240, 245, 0.4); margin-top: 6px; }

  /* traps */
  .cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 30px; margin-top: 44px; }
  .card { padding: 38px 36px; }
  .card .n { font-family: 'Jersey 20', sans-serif; font-size: 58px; color: var(--addon-text); line-height: 1; margin-bottom: 18px; }
  .card h3 { font-size: 30px; font-weight: 700; line-height: 1.25; margin-bottom: 16px; }
  .card p { font-size: 23px; line-height: 1.5; color: rgba(240, 240, 245, 0.66); }

  /* services */
  .svc-split { display: grid; grid-template-columns: 1.25fr 1fr; gap: 30px; margin-top: 34px; align-items: start; }
  .svc-list ul.one { columns: 1; }
  .svc-list { padding: 30px 34px; }
  .svc-list h3, .svc-side h3 { font-size: 18px; font-weight: 600; letter-spacing: 0.14em; text-transform: uppercase; color: rgba(240, 240, 245, 0.5); margin-bottom: 16px; }
  .svc-list ul { list-style: none; columns: 2; column-gap: 30px; }
  .svc-list li { font-size: 22px; line-height: 1.35; padding: 7px 0; break-inside: avoid; color: rgba(240, 240, 245, 0.85); }
  .svc-list li small { display: inline-block; margin-left: 8px; font-size: 15px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--addon-text); }
  .svc-list .more { font-size: 20px; color: rgba(240, 240, 245, 0.45); margin-top: 8px; }
  .svc-side { display: flex; flex-direction: column; gap: 22px; }
  .svc-side .box { padding: 26px 30px; }
  .chips { display: flex; flex-wrap: wrap; gap: 10px; }
  .chip { padding: 8px 16px; border-radius: 999px; font-size: 20px; background: rgba(138, 151, 247, 0.14); color: #c3cafb; }
  .svc-side p { font-size: 22px; line-height: 1.45; color: rgba(240, 240, 245, 0.75); }
  .svc-side p b { color: var(--off-white); font-weight: 600; }
  .carrier-cards { display: grid; gap: 22px; margin-top: 34px; }
  .ccard { padding: 24px 28px; }
  .ccard h3 { font-size: 26px; font-weight: 700; margin-bottom: 10px; }
  .ccard p { font-size: 20px; line-height: 1.45; color: rgba(240, 240, 245, 0.66); margin-bottom: 12px; }
  .ccard .chip { font-size: 17px; padding: 6px 12px; }

  /* honest */
  .checks { flex: 1 1 auto; padding: 36px 40px; }
  .checks h3 { font-size: 18px; font-weight: 600; letter-spacing: 0.14em; text-transform: uppercase; color: rgba(240, 240, 245, 0.5); margin-bottom: 18px; }
  .checks li { list-style: none; display: flex; gap: 16px; align-items: flex-start; font-size: 24px; line-height: 1.45; color: rgba(240, 240, 245, 0.82); padding: 12px 0; border-top: 1px solid rgba(255, 255, 255, 0.06); }
  .checks li:first-of-type { border-top: 0; }
  .checks li::before { content: ''; flex: 0 0 auto; width: 14px; height: 14px; margin-top: 11px; border-radius: 3px; border: 2px solid var(--gold); }
  .checks .sb { margin-top: 18px; padding: 18px 22px; border-radius: 12px; background: rgba(255, 209, 102, 0.08); border: 1px solid rgba(255, 209, 102, 0.28); font-size: 21px; line-height: 1.45; color: rgba(240, 240, 245, 0.8); }
  .checks .sb b { color: var(--gold); font-weight: 600; }

  /* family */
  .family-grid { display: grid; grid-template-columns: repeat(8, 1fr); gap: 20px; margin-top: 44px; }
  .fam { display: flex; flex-direction: column; align-items: center; gap: 12px; opacity: 0.42; }
  .fam img { width: 112px; height: 112px; }
  .fam span { font-size: 20px; font-weight: 600; white-space: nowrap; }
  .fam.me { opacity: 1; }
  .fam.me img { filter: drop-shadow(0 0 26px rgba(138, 151, 247, 0.55)); transform: scale(1.12); }
  .gateway { display: flex; align-items: center; gap: 26px; margin-top: 44px; padding: 26px 32px; }
  .gateway img { width: 84px; height: 84px; flex: 0 0 auto; }
  .gateway p { font-size: 23px; line-height: 1.5; color: rgba(240, 240, 245, 0.72); }
  .gateway p b { color: var(--off-white); }
  .term { margin-top: 22px; padding: 24px 32px; font-size: 23px; line-height: 1.75; }
  .term .p { color: var(--accent-light); }

  body.single .slide { display: none; }
  body.single .slide.active { display: block; }
''' % (colour, text_colour)


def footer(v):
    return f'<div class="footer"><img src="assets/icon.svg" alt=""> <b>Carrier for {e(v["name"])}</b> &nbsp;·&nbsp; justinholt.com/plugins/craft-carrier/docs/{e(v["slug"])}</div>'


def slide(n, cls, body, v, foot=True):
    return (f'<!-- {n} ============================================================ -->\n'
            f'<section class="slide {cls}" data-n="{n}">\n  <div class="inner">\n{body}\n  </div>\n'
            + (f'  {footer(v)}\n' if foot else '') + '</section>\n')


def eyebrow(text):
    return f'    <div class="eyebrow"><span class="line"></span> {text}</div>'


def info(v, cars):
    return [cars[c['handle']] for c in v['carriers']]


def s_cover(v):
    return slide(1, 'cover', f'''    <div class="copy">
{eyebrow('Carrier add-on')}
      <h1 class="gradient-text"><span>Carrier for</span>{e(v['name'])}</h1>
      <p class="tagline">{v['tagline']}</p>
      <p class="lede">{v['lede']}</p>
      <div class="badges">
        <span class="badge"><span class="dot"></span> Free &nbsp;·&nbsp; requires Carrier</span>
        <span class="badge">Craft 5.3+ · Commerce 5 · PHP 8.2+</span>
      </div>
    </div>
    <div class="mark"><img src="assets/icon.svg" alt=""></div>''', v, foot=False)


def s_does(v, cars):
    cs = info(v, cars)
    heading = 'Exactly what ' + e(v['name']) + ' can do' if len(cs) == 1 else 'What each one can do'
    if len(cs) == 1:
        has = set(cs[0]['capabilities']['features'])
        cells = ''.join(
            f'<div class="feat {"on" if k in has else "off"}"><span class="ic">{"&#10003;" if k in has else "&ndash;"}</span>{label}</div>'
            for k, label in FEATURES)
        body = f'<div class="feats">{cells}</div>'
        legend = 'Greyed out: not declared by this carrier, so Carrier never offers it.'
    else:
        used = [(k, label) for k, label in FEATURES if any(k in c['capabilities']['features'] for c in cs)]
        head = '<th>Feature</th>' + ''.join(f'<th>{e(c["short"])}</th>' for c in v['carriers'])
        rows = ''.join(
            f'<tr><td>{label}</td>' + ''.join(
                '<td><span class="yes">&#10003;</span></td>' if k in c['capabilities']['features'] else '<td><span class="no">&mdash;</span></td>'
                for c in cs) + '</tr>'
            for k, label in used)
        body = f'<div class="matrix panel"><table><tr>{head}</tr>{rows}</table></div>'
        legend = 'A dash: not declared by that carrier, so Carrier never offers it there.'
    return slide(2, 'does', f'''{eyebrow('What it does')}
    <h2 class="gradient-text">{heading}</h2>
    <p class="lede">Read from the carrier&rsquo;s own declaration &mdash; Carrier builds its buttons from this list, so it cannot offer something that was never built.</p>
    {body}
    <div class="legend">{legend}</div>''', v)


def field_input(f):
    t = f.get('type')
    if f.get('secret'):
        return '<div class="input secret">••••••••••••</div>'
    if t == 'boolean':
        return '<div class="toggle"></div>'
    if t == 'select':
        opts = f.get('options') or {}
        default = f.get('default')
        first = opts.get(default) if isinstance(opts, dict) and default in opts else (next(iter(opts.values()), '') if isinstance(opts, dict) else '')
        if isinstance(first, dict):
            first = first.get('label', '')
        return f'<div class="input">{e(first)} ▾</div>'
    value = f.get('placeholder') or (f.get('default') if t == 'number' else '') or ''
    return f'<div class="input">{e(value)}</div>'


def s_connect(v, cars):
    c = v['carriers'][v.get('formCarrier', 0)]
    car = cars[c['handle']]
    fields = [f for f in car['fields'] if f.get('type') not in ('heading', 'copyable') and f.get('name')]
    shown, rest = fields[:8], fields[8:]
    out = ''.join(
        f'<div class="field"><label>{e(f["label"])}{"<i>*</i>" if f.get("required") else ""}</label>{field_input(f)}</div>'
        for f in shown)
    more = (f'<div class="more">+ {len(rest)} more &mdash; {", ".join(e(f["label"]) for f in rest[:3])}{"…" if len(rest) > 3 else ""}</div>'
            if rest else '')
    return slide(3, 'connect', f'''    <div class="split">
      <div class="copy">
{eyebrow('Connecting')}
        <h2 class="gradient-text">{v.get('connectHeading', 'One screen to connect')}</h2>
        <p class="lede">{v['connect']}</p>
      </div>
      <div class="form panel">
        <div class="title"><img src="assets/icon.svg" alt=""> New connection &mdash; {e(car['name'])}</div>
        <div class="cols">{out}</div>
        {more}
      </div>
    </div>''', v)


def s_traps(v):
    cards = ''.join(f'''
      <div class="card panel"><div class="n">0{i + 1}</div><h3>{t['title']}</h3><p>{t['body']}</p></div>''' for i, t in enumerate(v['traps']))
    return slide(4, 'traps', f'''{eyebrow('Already handled')}
    <h2 class="gradient-text">{v.get('trapsHeading', 'It knows ' + e(v['name']) + '&rsquo;s quirks')}</h2>
    <p class="lede">The things a first {e(v['name'])} integration learns the hard way, written into the add-on so yours doesn&rsquo;t have to.</p>
    <div class="cards">{cards}
    </div>''', v)


def points_text(cap):
    if not cap.get('pointsMode'):
        return None
    types = [POINT_TYPES.get(t, t) for t in cap.get('pointTypes') or []]
    kinds = ', '.join(types[:-1]) + (' and ' if len(types) > 1 else '') + types[-1] if types else 'points'
    return f'<b>{kinds[0].upper() + kinds[1:]}</b> &mdash; {POINT_MODES.get(cap["pointsMode"], cap["pointsMode"])}'


def services_of(car):
    s = car['services']
    return list(s.values()) if isinstance(s, dict) else []


def s_services(v, cars):
    cs = info(v, cars)
    if len(cs) == 1:
        car = cs[0]
        cap = car['capabilities']
        svcs = services_of(car)
        shown = svcs[:12]
        items = ''.join(
            f'<li>{e(s["name"])}{"<small>point</small>" if s.get("points") else ""}{"<small>intl</small>" if s.get("international") else ""}</li>'
            for s in shown)
        more = f'<div class="more">and {len(svcs) - len(shown)} more</div>' if len(svcs) > len(shown) else ''
        chips = ''.join(f'<span class="chip">{e(FORMATS.get(f, f))}</span>' for f in cap['labelFormats'])
        pts = points_text(cap)
        extra = []
        if cap.get('maxParcelWeightG'):
            g = cap['maxParcelWeightG']
            lb = g / 453.59237
            weight = f'{lb:.0f} lb' if abs(lb - round(lb)) < 0.01 and g % 1000 else f'{g / 1000:g} kg'
            extra.append(f'Up to <b>{weight}</b> a parcel')
        if cap.get('trackingBatchSize', 1) > 1:
            extra.append(f'Tracks <b>{cap["trackingBatchSize"]}</b> numbers a request')
        if cap.get('originCountries'):
            extra.append('Ships from <b>' + ', '.join(cap['originCountries']) + '</b>')
        side = (f'<div class="box panel"><h3>Label formats</h3><div class="chips">{chips}</div></div>'
                + (f'<div class="box panel"><h3>Pickup points</h3><p>{pts}</p></div>' if pts else '')
                + (f'<div class="box panel"><h3>Limits</h3><p>{"<br>".join(extra)}</p></div>' if extra else ''))
        body = f'''<div class="svc-split">
      <div class="svc-list panel"><h3>{len(svcs)} service{"s" if len(svcs) != 1 else ""}</h3><ul{' class="one"' if len(svcs) <= 6 else ""}>{items}</ul>{more}</div>
      <div class="svc-side">{side}</div>
    </div>'''
    else:
        cols = 3 if len(cs) > 4 else 2
        cards = ''
        for c, car in zip(v['carriers'], cs):
            cap = car['capabilities']
            svcs = services_of(car)
            names = ', '.join(e(s['name']) for s in svcs) if svcs else 'No labels &mdash; tracking and points only'
            pts = points_text(cap)
            chips = ''.join(f'<span class="chip">{e(FORMATS.get(f, f))}</span>' for f in cap['labelFormats'])
            cards += (f'<div class="ccard panel"><h3>{e(car["name"])}</h3><p>{names}</p>'
                      + (f'<p>{pts}</p>' if pts else '') + f'<div class="chips">{chips}</div></div>')
        body = f'<div class="carrier-cards" style="grid-template-columns: repeat({cols}, 1fr)">{cards}</div>'
    return slide(5, 'services', f'''{eyebrow('Services, labels, points')}
    <h2 class="gradient-text">{v.get('servicesHeading', 'What a method can ship with')}</h2>
    <p class="lede">A checkout method picks one service; the connection picks the label format your printer takes.</p>
    {body}''', v)


def s_honest(v, cars):
    items = ''.join(f'<li>{u}</li>' for u in v['unverified'])
    lede = v.get('honestLede') or (f'Written against {v["source"]}, without a live account. Anything that could not be '
                                   f'confirmed is marked <b>UNVERIFIED</b> in the carrier class, where whoever debugs it will look.')
    return slide(6, 'honest', f'''    <div class="split">
      <div class="copy">
{eyebrow('Honest about it')}
        <h2 class="gradient-text">{v.get('honestHeading', 'Built from the published API')}</h2>
        <p class="lede">{lede}</p>
      </div>
      <div class="checks panel">
        <h3>Check before going live</h3>
        <ul>{items}</ul>
        <div class="sb">Test every connection in <b>{v['sandbox']}</b>.</div>
      </div>
    </div>''', v)


def s_family(v):
    grid = ''.join(f'<div class="fam{" me" if k == v["key"] else ""}"><img src="assets/family/{k}.svg" alt=""><span>{FAMILY_NAMES[k]}</span></div>' for k in FAMILY)
    return slide(7, 'family', f'''{eyebrow('Installing')}
    <h2 class="gradient-text">One of eight free add-ons</h2>
    <div class="family-grid">{grid}</div>
    <div class="gateway panel"><img src="assets/carrier.svg" alt="">
      <p><b>Needs Carrier</b>, the gateway &mdash; $149, then $129 a year. It owns checkout pricing, the claim that
        stops a label being bought twice, printing, tracking and the point picker. Every add-on is free, and one licence drives all of them.</p></div>
    <div class="term panel mono"><span class="p">$</span> composer require justinholtweb/craft-carrier justinholtweb/craft-carrier-{e(v['key'])}<br><span class="p">$</span> php craft plugin/install carrier &amp;&amp; php craft plugin/install carrier-{e(v['key'])}</div>''', v)


def deck(v, cars):
    return f'''<!DOCTYPE html>
<!-- Generated by craft-carrier/promos/addons/generate.py from decks.json and carriers.json.
     Edit those and regenerate; a hand edit here is lost next time. -->
<html lang="en">
<head>
<meta charset="utf-8">
<title>Carrier for {e(v['name'])} — Plugin Store promos</title>
<link rel="stylesheet" href="fonts.css">
<style>{css(v['colour'], v.get('textColour', v['colour']))}</style>
</head>
<body>
{s_cover(v)}
{s_does(v, cars)}
{s_connect(v, cars)}
{s_traps(v)}
{s_services(v, cars)}
{s_honest(v, cars)}
{s_family(v)}
<script>
  var n = new URLSearchParams(location.search).get('s');
  if (n) {{
    document.body.classList.add('single');
    var el = document.querySelector('.slide[data-n="' + n + '"]');
    if (el) el.classList.add('active');
  }}
</script>
</body>
</html>
'''


BUILD = '''#!/usr/bin/env bash
# Renders the Plugin Store promo images from slides.html.
# Output: promos/out/carrier-{key}-promo-N.jpg at 1920x1080 (rendered at 2x, downsampled).
# Promos ship as JPEG, never PNG. slides.html is generated by craft-carrier/promos/addons/generate.py.
set -euo pipefail

cd "$(dirname "$0")"
CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
SLIDES=${{1:-"1 2 3 4 5 6 7"}}

# Inline the self-hosted fonts so Chrome's file:// origin rules can't block them.
{{
  echo "@font-face{{font-family:'Inter';font-style:normal;font-weight:400 700;font-display:block;src:url(data:font/woff2;base64,$(base64 < assets/inter-latin.woff2 | tr -d '\\n')) format('woff2');}}"
  echo "@font-face{{font-family:'Jersey 20';font-style:normal;font-weight:400;font-display:block;src:url(data:font/woff2;base64,$(base64 < assets/jersey-20-latin.woff2 | tr -d '\\n')) format('woff2');}}"
}} > fonts.css

mkdir -p out
for n in $SLIDES; do
  "$CHROME" \\
    --headless=new \\
    --disable-gpu \\
    --hide-scrollbars \\
    --allow-file-access-from-files \\
    --force-device-scale-factor=2 \\
    --window-size=1920,1080 \\
    --virtual-time-budget=5000 \\
    --screenshot="out/carrier-{key}-promo-$n.png" \\
    "file://$PWD/slides.html?s=$n" >/dev/null 2>&1
  # Chrome can only write PNG; downsample and convert in one pass, then drop the PNG.
  sips --resampleWidth 1920 -s format jpeg -s formatOptions 90 \\
    "out/carrier-{key}-promo-$n.png" --out "out/carrier-{key}-promo-$n.jpg" >/dev/null
  rm "out/carrier-{key}-promo-$n.png"
  echo "  built out/carrier-{key}-promo-$n.jpg"
done
'''

README = '''# Plugin Store promo images

Seven 1920×1080 JPEGs for the Carrier for {name} listing on the Craft Plugin Store, in the theme of
Carrier's page at [justinholt.com/plugins/craft-carrier](https://justinholt.com/plugins/craft-carrier).

```bash
./build.sh          # all slides
./build.sh "2 4"    # just slides 2 and 4
```

Output lands in `out/` as `carrier-{key}-promo-N.jpg`. Promos ship as JPEG, never PNG. `fonts.css`
is generated and not checked in.

**`slides.html` is generated — don't edit it here.** Every add-on deck comes from one template in
the Carrier repo, `craft-carrier/promos/addons/generate.py`, so the eight stay consistent. Slides 2
(what it does), 3 (the connection form) and 5 (services, label formats, points) are drawn from the
carrier's own `capabilities()`, `services()` and `settingsFields()`; the rest of the copy is in
`craft-carrier/promos/addons/decks.json`. The icon is generated too, by
`craft-carrier/promos/icon-gen.py`, and carries a generic mark rather than the carrier's logo.

| # | Slide |
|---|-------|
| 1 | Cover — the add-on's icon, free, requires Carrier |
| 2 | What it does, from the declared features |
| 3 | The connection form |
| 4 | Three {name} traps the add-on already handles |
| 5 | Services, label formats and pickup points |
| 6 | Built from the published API — what to check in the sandbox |
| 7 | The eight add-ons, Carrier itself, and how to install this one |
'''


def main(only):
    decks = json.load(open(os.path.join(HERE, 'decks.json')))
    packages = json.load(open(os.path.join(HERE, 'carriers.json')))
    cars = {h: c for carriers in packages.values() for h, c in carriers.items()}
    for key, v in decks.items():
        if only and key not in only:
            continue
        v['key'] = key
        out = os.path.join(SITES, f'craft-carrier-{key}', 'promos')
        os.makedirs(os.path.join(out, 'assets', 'family'), exist_ok=True)
        for font in ('inter-latin.woff2', 'jersey-20-latin.woff2'):
            shutil.copy(os.path.join(PROMOS, 'assets', font), os.path.join(out, 'assets', font))
        shutil.copy(os.path.join(PROMOS, 'assets', 'watermark.svg'), os.path.join(out, 'assets', 'watermark.svg'))
        shutil.copy(os.path.join(PROMOS, 'assets', 'icon.svg'), os.path.join(out, 'assets', 'carrier.svg'))
        shutil.copy(os.path.join(PROMOS, 'assets', 'addons', f'{key}.svg'), os.path.join(out, 'assets', 'icon.svg'))
        for k in FAMILY:
            shutil.copy(os.path.join(PROMOS, 'assets', 'addons', f'{k}.svg'), os.path.join(out, 'assets', 'family', f'{k}.svg'))
        open(os.path.join(out, 'slides.html'), 'w').write(deck(v, cars))
        open(os.path.join(out, 'build.sh'), 'w').write(BUILD.format(key=key))
        os.chmod(os.path.join(out, 'build.sh'), 0o755)
        open(os.path.join(out, 'README.md'), 'w').write(README.format(name=v['name'], key=key))
        open(os.path.join(out, '.gitignore'), 'w').write('fonts.css\n')
        print('  wrote', os.path.relpath(out, SITES))


if __name__ == '__main__':
    main(sys.argv[1:])
