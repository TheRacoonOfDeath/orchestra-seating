#!/usr/bin/env python3
"""End-to-end test of orchestra-seating with the other Orchestra plugins. Started by run.sh.

Environment: E2E_BASE_URL, E2E_WP_CLI (wrapper script), E2E_WP_PATH, E2E_SEED (IDs from seed.php).
"""
import html
import io
import json
import os
import re
import subprocess
import sys
import tempfile
import zipfile

BASE = os.environ["E2E_BASE_URL"]
WPC = os.environ["E2E_WP_CLI"]
SEED = json.loads(os.environ["E2E_SEED"])
WORK = tempfile.mkdtemp(prefix="orchestra-e2e-")
failures = []


def check(condition, label):
    print(("PASS " if condition else "FAIL ") + label)
    if not condition:
        failures.append(label)


def wp(*args):
    return subprocess.run([WPC, *args], capture_output=True, text=True).stdout.strip()


def wp_eval(code):
    return wp("eval", code)


def wp_json(code):
    output = wp_eval(code)
    try:
        return json.loads(output)
    except json.JSONDecodeError:
        print("Unexpected output:", output[:500])
        return None


def user_id(login):
    return int(wp("user", "get", login, "--field=ID"))


class Session:
    def __init__(self, user=None, password="pass"):
        self.jar = os.path.join(WORK, f"cookies-{user or 'anon'}-{os.urandom(4).hex()}.txt")
        open(self.jar, "w").close()
        if user:
            self.request("/wp-login.php", data={
                "log": user, "pwd": password, "wp-submit": "Log In", "testcookie": "1",
            }, cookie="wordpress_test_cookie=WP%20Cookie%20check")

    def request(self, path, data=None, cookie=None, follow=True, files=None):
        """Returns (status, headers, body). Values in `data` may be lists. `files` is a list of
        (field, path, file name) and sends the form as multipart/form-data."""
        header_file = os.path.join(WORK, "headers.txt")
        cmd = ["curl", "-s", "-c", self.jar, "-b", self.jar, "-D", header_file, "-o", "-"]
        if follow:
            cmd.append("-L")
        if cookie:
            cmd += ["--cookie", cookie]
        if files is not None:
            for key, value in (data or {}).items():
                for v in (value if isinstance(value, list) else [value]):
                    cmd += ["--form-string", f"{key}={v}"]
            for key, file_path, file_name in files:
                cmd += ["-F", f"{key}=@{file_path};filename={file_name}"]
        elif data is not None:
            for key, value in data.items():
                for v in (value if isinstance(value, list) else [value]):
                    cmd += ["--data-urlencode", f"{key}={v}"]
        cmd.append(path if path.startswith("http") else BASE + path)
        result = subprocess.run(cmd, capture_output=True)
        raw = open(header_file, encoding="latin-1", newline="").read()
        blocks = [b for b in raw.split("\r\n\r\n") if b.strip()]
        last = blocks[-1] if blocks else ""
        status = int(re.search(r"HTTP/[\d.]+ (\d+)", last).group(1)) if last else 0
        headers = {}
        for line in last.split("\r\n")[1:]:
            if ":" in line:
                k, v = line.split(":", 1)
                headers[k.strip().lower()] = v.strip()
        return status, headers, result.stdout

    def get(self, path):
        status, _, body = self.request(path)
        return status, body.decode()

    def post(self, path, data):
        status, _, body = self.request(path, data=data)
        return status, body.decode()


def field(page, name):
    match = re.search(r'name=["\']' + re.escape(name) + r'["\'][^>]*value=["\']([^"\']*)', page) \
        or re.search(r'value=["\']([^"\']*)["\'][^>]*name=["\']' + re.escape(name) + r'["\']', page)
    return html.unescape(match.group(1)) if match else None


def text(page):
    """The visible text of a page, for checks independent of markup."""
    page = re.sub(r"<(script|style)[^>]*>.*?</\1>", " ", page, flags=re.S)
    page = re.sub(r"<span[^>]*\shidden>[^<]*</span>", " ", page)
    return html.unescape(re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", page)))



ids = {login: user_id(login) for login in ["admin", "organizer", "conductor", "mia", "max", "mo", "lena", "sam"]}
PROJECT = SEED["project"]
AUTUMN = SEED["autumn"]
PAGES = wp_json('echo json_encode(["projects" => Orchestra\\Core\\Ui\\Pages::url("orchestra_projects"),'
                ' "seating" => Orchestra\\Core\\Ui\\Pages::url("orchestra_seating")]);')
PROJECTS = PAGES["projects"].replace(BASE, "")
MASTER = PAGES["seating"].replace(BASE, "")
TAB = f"{PROJECTS}&orchestra_project={PROJECT}&orchestra_tab=seating"
OVERVIEW = f"{PROJECTS}&orchestra_project={PROJECT}"

admin, organizer, conductor = Session("admin", "admin"), Session("organizer"), Session("conductor")
mia, sam = Session("mia"), Session("sam")


def seats(project=PROJECT):
    rows = wp_json('global $wpdb; echo json_encode($wpdb->get_results("SELECT user_id, seat_row, seat_column FROM'
                   ' {$wpdb->prefix}orchestra_seating_seats WHERE project_id = ' + str(project) + ' ORDER BY seat_row, seat_column", ARRAY_A));') or []
    names = {v: k for k, v in ids.items()}
    return {names.get(int(r["user_id"]), r["user_id"]): f'{r["seat_row"]}-{r["seat_column"]}' for r in rows}


def form_of(page, action="orchestra_seating_save"):
    return next((f for f in re.findall(r"<form.*?</form>", page, flags=re.S) if f'value="{action}"' in f), "")


def save(session, placement, op="save", organizers=True, page=None, extra=None):
    """Submits the seating form like a browser: every select and the organizer switch."""
    if page is None:
        _, page = session.get(TAB)
    form = form_of(page)
    data = {
        "action": "orchestra_seating_save", "project": str(PROJECT), "op": op,
        "_wpnonce": field(form, "_wpnonce") or "missing", "redirect_to": field(form, "redirect_to") or TAB,
    }
    for user in re.findall(r'name="seat\[(\d+)\]"', form):
        login = next((k for k, v in ids.items() if v == int(user)), "")
        data[f"seat[{user}]"] = placement.get(login, "")
    if organizers:
        data["seat_organizers"] = "1"
    data.update(extra or {})
    return session.post("/wp-admin/admin-post.php", data)


def save_master(session, placement, op="save"):
    """Submits the form of the master seating like a browser."""
    _, page = session.get(MASTER)
    form = form_of(page, "orchestra_seating_master_save")
    data = {
        "action": "orchestra_seating_master_save", "op": op,
        "_wpnonce": field(form, "_wpnonce") or "missing", "redirect_to": field(form, "redirect_to") or MASTER,
    }
    for user in re.findall(r'name="seat\[(\d+)\]"', form):
        login = next((k for k, v in ids.items() if v == int(user)), "")
        data[f"seat[{user}]"] = placement.get(login, "")
    return session.post("/wp-admin/admin-post.php", data)


def cells(page):
    return len(re.findall(r"data-seating-cell=", page))


def cell_classes(page, seat):
    match = re.search(r'class="orchestra-seating__cell([^"]*)" data-seating-seat="' + seat + '"', page)
    return match.group(1).split() if match else None


def color_of(page, name):
    match = re.search(r'<div class="orchestra-seating__person"([^>]*)>\s*<span class="orchestra-seating__name">'
                      + re.escape(name) + '<', page)
    color = re.search(r'data-seating-color="(\d+)"', match.group(1)) if match else None
    return color.group(1) if color else None


def tray(page):
    part = page.split("data-seating-tray", 1)[1].split("</section>", 1)[0] if "data-seating-tray" in page else ""
    return text(part)


# --- Plugin dependencies ----------------------------------------------------------------------
circular = wp_json('WP_Plugin_Dependencies::initialize(); echo json_encode(array_values(array_filter(array_keys(get_plugins()),'
                   ' fn ($p) => WP_Plugin_Dependencies::has_circular_dependency($p))));')
check(circular == [], "WordPress finds no circular plugin dependencies")

# --- Schema -------------------------------------------------------------------------------------
# Version 1 stored a piece per seating; the update drops the column.
old = wp_json('global $wpdb; $wpdb->query("ALTER TABLE {$wpdb->prefix}orchestra_seating_plans ADD COLUMN piece_id bigint(20) unsigned DEFAULT NULL");'
              ' update_option("orchestra_seating_schema_version", 1);'
              ' echo json_encode($wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}orchestra_seating_plans"));')
check("piece_id" in old, "the seating of version 1 has a piece")
columns = wp_json('global $wpdb; echo json_encode([$wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}orchestra_seating_plans"),'
                  ' (int) get_option("orchestra_seating_schema_version")]);')
check("piece_id" not in columns[0] and columns[1] == 2, "updating drops the piece of the seating")

# --- Capabilities and tab ---------------------------------------------------------------------
caps = wp_json('echo json_encode(array_map(fn ($r) => array_keys(array_filter(get_role($r)->capabilities)),'
               ' ["orchestra_member", "orchestra_conductor", "orchestra_organizer"]));')
check("orchestra_seating_plan_read" in caps[0] and "orchestra_seating_plan_manage" not in caps[0],
      "members may only see the seating")
check(all("orchestra_seating_plan_manage" in c for c in caps[1:]), "conductors and organizers arrange the seating")



def phase(status):
    wp("post", "meta", "update", str(PROJECT), "orchestra_projects_status", status)


_, page = mia.get(TAB)
check("The seating has not been finished yet." in text(page) and "data-seating" not in page,
      "until the parts are assigned, members only learn that the seating is not finished")
phase("parts_assigned")
_, page = mia.get(OVERVIEW)
check("orchestra_tab=seating" not in page, "members see no seating before it is arranged")
phase("open")
PDF = f"/wp-admin/admin-post.php?action=orchestra_seating_pdf&project={PROJECT}"
_, page = conductor.get(TAB)
check("data-seating-form" in page and "Not seated" in page, "conductors open the seating editor")
check(all(name in tray(page) for name in ["Mia", "Max", "Olga", "Lena-Marie"]) and "Carl" not in tray(page)
      and "Mo" not in tray(page).split(), "accepted members and organizers are to be seated, conductors and those who declined are not")
check(cells(page) == 4, "the grid starts with a seat for everyone")
labels = re.findall(r'class="orchestra-seating__label"[^>]*>([^<]*)<', page)
check(labels == ["A", "1", "2", "3", "4"] and "Row A, seat 4" in page,
      "rows are labelled with letters from the front, columns with numbers from the left")

# --- Arranging ----------------------------------------------------------------------------------
_, page = save(conductor, {"mia": "1-1", "max": "1-2", "organizer": "1-3", "lena": "1-4"})
check("The seating has been saved." in text(page) and seats() == {"mia": "1-1", "max": "1-2", "organizer": "1-3", "lena": "1-4"},
      "conductors seat people")
_, page = save(organizer, {"mia": "1-2", "max": "1-1", "organizer": "1-3", "lena": "1-4"})
check(seats()["mia"] == "1-2" and seats()["max"] == "1-1", "organizers rearrange the seating")
_, page = save(conductor, {"mia": "1-1", "max": "1-1"})
check("Two people cannot sit on the same seat." in text(page) and seats()["mia"] == "1-2", "a seat holds one person")
_, page = save(conductor, {"mia": "5-1"})
check("outside the grid" in text(page), "seats outside the grid are rejected")

# --- Viewing ------------------------------------------------------------------------------------
phase("parts_assigned")
_, page = mia.get(OVERVIEW)
check("orchestra_tab=seating" in page, "members see the seating once it is arranged")
_, page = mia.get(TAB)
plain = text(page)
check("data-seating-form" not in page and "Mia Horn 1" in plain and "Max Horn 2" in plain and "Olga —" in plain
      and "Voices in Pirates of the Caribbean (1 of 2)" in plain, "members see names and voices, without editing")
check(re.search(f'data-seating-voice="{SEED["dance"]}"[^>]* hidden>', page) is not None and 'data-seating-step="1"' in page,
      "the voices of every piece are there to step through")
check("Lena-Marie Sophie von Hohenzollern-Sigmaringen" in plain, "long names are shown")
# Row A: Max (Horn 2), Mia (Horn 1), Olga and Lena without a part in Pirates of the Caribbean.
check(color_of(page, "Mia") == "0" and color_of(page, "Max") == "1" and color_of(page, "Olga") is None,
      "every voice has its own color, people without a voice have none")
check({"is-line-left", "is-line-right", "is-line-front"} <= set(cell_classes(page, "1-1"))
      and {"is-line-left", "is-line-right"} <= set(cell_classes(page, "1-2")),
      "every person is outlined, also towards neighbours with different voices and the edge of the grid")
check("is-line-right" not in cell_classes(page, "1-3") and "is-line-left" not in cell_classes(page, "1-4"),
      "there is no line between neighbours with the same voice")
check("Download PDF" not in page, "members see no PDF download")
status, _, body = mia.request(PDF)
check(status == 403 and not body.startswith(b"%PDF"), "members cannot download the seating")
_, page = conductor.get(TAB)
check("Download PDF" in page, "conductors can download the seating as a PDF")
status, headers, body = conductor.request(PDF)
check(status == 200 and headers.get("content-type") == "application/pdf" and body.startswith(b"%PDF-")
      and "Spring%20Concert%20-%20Seating.pdf" in headers.get("content-disposition", ""),
      "the seating is downloaded as a PDF")
status, _, body = organizer.request(PDF)
check(status == 200 and body.startswith(b"%PDF-"), "organizers can download the seating as a PDF")
with open(os.path.join(WORK, "seating.pdf"), "wb") as handle:
    handle.write(body)
status, _, _ = sam.request(PDF)
check(status == 403, "others cannot download the seating")
_, page = mia.get(f'{TAB}&seating_piece={SEED["dance"]}')
check("Mia Violin 1" in text(page) and "Max —" in text(page) and "Voices in Lord of the Dance (2 of 2)" in text(page),
      "the arrows show the voices of the next piece without JavaScript")
status, _ = save(mia, {"mia": "1-1"}, page=page)
check(status == 403 or "orchestra_seating_save" not in form_of(page), "members cannot change the seating")
status, _ = sam.post("/wp-admin/admin-post.php", {"action": "orchestra_seating_save", "project": str(PROJECT)})
check(status in (403, 400) or seats()["mia"] == "1-2", "others cannot change the seating")

phase("open")

# --- Voices -------------------------------------------------------------------------------------
PIRATES, HORN1, HORN2 = SEED["pirates"], SEED["pirates:Horn 1"], SEED["pirates:Horn 2"]


def parts_in(piece):
    """{login: part ID} of the project's assignments in the piece"""
    rows = wp_json('echo json_encode(array_map(fn($a) => [$a->userId, $a->pieceId, $a->partId],'
                   f' Orchestra\\Projects\\Plugin::instance()->projects()->assignments({PROJECT})));') or []
    names = {v: k for k, v in ids.items()}
    return {names.get(user, user): part for user, piece_id, part in rows if piece_id == piece}


arranged = {"mia": "1-2", "max": "1-1", "organizer": "1-3", "lena": "1-4"}
_, page = conductor.get(TAB)
form = form_of(page)
mia_select = re.search(rf'<select[^>]*name="voice\[{PIRATES}\]\[{ids["mia"]}\]"[^>]*>.*?</select>', form, flags=re.S)
check(mia_select is not None and re.search(rf'value="{HORN1}"[^>]*selected', mia_select.group(0)) is not None
      and field(form, f"voice_was[{PIRATES}][{ids['mia']}]") == str(HORN1),
      "conductors choose the voices in the seating with a select per piece")
# Mia played Horn 1 and later Horn 2 of the piece in two other projects.
wp_eval('$r = new Orchestra\\Projects\\Assignment\\AssignmentRepository($GLOBALS["wpdb"],'
        ' new Orchestra\\Projects\\Database\\Schema($GLOBALS["wpdb"]));'
        f' $r->insert(999998, {PIRATES}, {HORN1}, {ids["mia"]}, 1); $r->insert(999999, {PIRATES}, {HORN2}, {ids["mia"]}, 1);')
_, played_page = conductor.get(TAB)


def option_class(page, user, part):
    select = re.search(rf'<select[^>]*name="voice\[{PIRATES}\]\[{ids[user]}\]"[^>]*>.*?</select>', page, flags=re.S)
    option = re.search(rf'<option value="{part}"([^>]*)>', select.group(0)) if select else None
    match = re.search(r'orchestra-seating__played--(\w+)', option.group(1)) if option else None
    return match.group(1) if match else ""


check(option_class(played_page, "mia", HORN2) == "last" and option_class(played_page, "mia", HORN1) == "earlier"
      and option_class(played_page, "lena", HORN1) == "",
      "the voice select is green for the part played last time, yellow for one played before")
wp_eval('global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}orchestra_projects_assignments'
        ' WHERE project_id IN (999998, 999999)");')
_, page = save(conductor, arranged, op="voices", page=page, extra={
    f"voice[{PIRATES}][{ids['lena']}]": str(HORN2), f"voice_was[{PIRATES}][{ids['lena']}]": "",
    f"voice[{PIRATES}][{ids['mia']}]": str(HORN2), f"voice_was[{PIRATES}][{ids['mia']}]": str(HORN1),
})
check("The voice has been changed." in text(page) and parts_in(PIRATES) == {"mia": HORN2, "max": HORN2, "lena": HORN2},
      f"changing a voice changes the assignment in orchestra-projects: {parts_in(PIRATES)}")
check(seats() == arranged, "changing a voice keeps the seating")
_, page = save(organizer, arranged, op="voices", extra={
    f"voice[{PIRATES}][{ids['lena']}]": "", f"voice_was[{PIRATES}][{ids['lena']}]": str(HORN2),
    f"voice[{PIRATES}][{ids['mia']}]": str(HORN1), f"voice_was[{PIRATES}][{ids['mia']}]": str(HORN2),
})
check(parts_in(PIRATES) == {"mia": HORN1, "max": HORN2}, "organizers change voices too, and remove them with —")
save(conductor, arranged, extra={f"voice[{PIRATES}][{ids['mia']}]": str(HORN2),
                                 f"voice_was[{PIRATES}][{ids['mia']}]": str(HORN2)})
check(parts_in(PIRATES)["mia"] == HORN1, "voices that were not changed in the form are left alone")
_, page = save(conductor, arranged, op="voices", extra={
    f"voice[{PIRATES}][{ids['sam']}]": str(HORN1), f"voice_was[{PIRATES}][{ids['sam']}]": ""})
check("sam" not in parts_in(PIRATES), "only the people of the seating get a voice")
phase("parts_assigned")
_, page = mia.get(TAB)
check('name="voice[' not in page and "Mia" in text(page), "members see the voices without selects")
_, page = save(conductor, arranged, op="voices", extra={
    f"voice[{PIRATES}][{ids['lena']}]": str(HORN2), f"voice_was[{PIRATES}][{ids['lena']}]": ""})
check(parts_in(PIRATES).get("lena") == HORN2, "voices can still be changed once the parts are assigned")
save(conductor, arranged, op="voices", extra={
    f"voice[{PIRATES}][{ids['lena']}]": "", f"voice_was[{PIRATES}][{ids['lena']}]": str(HORN2)})
phase("open")

# --- Grid ---------------------------------------------------------------------------------------
placement = {"mia": "1-2", "max": "1-1", "organizer": "1-3", "lena": "1-4"}
_, page = conductor.get(TAB)
check('value="insert_row-2"' in page and 'value="insert_row-1"' in page and 'value="insert_column-5"' in page
      and 'value="delete_column-4"' in page and 'value="delete_row-1"' not in page,
      "there are buttons to insert rows and columns everywhere and to delete them, but not the last row")
_, page = save(conductor, placement, op="insert_row-1")
check(cells(page) == 8 and seats() == {"max": "2-1", "mia": "2-2", "organizer": "2-3", "lena": "2-4"},
      "a row is inserted in front of the others, which move back")
_, page = save(conductor, seats(), op="insert_column-2")
check(cells(page) == 10 and seats() == {"max": "2-1", "mia": "2-3", "organizer": "2-4", "lena": "2-5"},
      "a column is inserted between two others")
check(re.search(r'value="delete_column-3"[^>]*data-orchestra-confirm=', page) is not None
      and re.search(r'value="delete_column-2"[^>]*data-orchestra-confirm=', page) is None,
      "deleting a column with people asks first")
_, page = save(conductor, seats(), op="delete_column-3")
check(cells(page) == 8 and seats() == {"max": "2-1", "organizer": "2-3", "lena": "2-4"} and "Mia" in tray(page),
      "deleting a column unseats the people on it")
_, page = save(conductor, seats(), op="delete_row-1")
check(cells(page) == 4 and seats() == {"max": "1-1", "organizer": "1-3", "lena": "1-4"}, "the rows behind move forward")
_, page = save(conductor, seats(), op="delete_row-1")
check("The last row or column cannot be deleted." in text(page) and cells(page) == 4, "the last row stays")
_, page = save(conductor, seats(), op="delete_column-2")
check(cells(page) == 3 and seats() == {"max": "1-1", "organizer": "1-2", "lena": "1-3"}, "columns are deleted anywhere")
between = {"insert_person": str(ids["mia"]), "insert_at": "1-2"}
_, page = save(conductor, seats(), op="insert_between", extra=between)
check(cells(page) == 4 and seats() == {"max": "1-1", "mia": "1-2", "organizer": "1-3", "lena": "1-4"},
      "putting someone between two people of a full row adds a column and moves the others aside")
_, page = save(conductor, {"mia": "1-2", "organizer": "1-3", "lena": "1-4"}, op="insert_between",
               extra={"insert_person": str(ids["max"]), "insert_at": "1-4"})
check(seats() == {"mia": "1-1", "organizer": "1-2", "max": "1-3", "lena": "1-4"},
      "the others move towards the nearest empty seat of the row")
_, page = save(conductor, seats(), op="insert_between", extra={"insert_person": str(ids["sam"]), "insert_at": "1-2"})
check("The person cannot be seated there." in text(page), "only people of the seating can be put between others")
save(conductor, placement)

# --- Organizers ---------------------------------------------------------------------------------
save(conductor, placement, organizers=False)
_, page = conductor.get(TAB)
check("organizer" not in seats() and "Olga" not in text(page).split("Not seated", 1)[1], "organizers can be left out")
save(conductor, placement, organizers=True)
_, page = conductor.get(TAB)
check("Olga" in tray(page), "organizers are seated again when switched on")

# --- Master seating ---------------------------------------------------------------------------
_, page = conductor.get(TAB)
check("There is no master seating yet." in text(page) and 'value="apply_master"' not in page,
      "without a master seating there is nothing to apply")
_, page = save(conductor, placement, op="apply_master")
check("There is no master seating yet." in text(page) and seats().get("mia") == "1-2", "nothing is applied")
status, page = mia.get(MASTER)
check("data-seating-form" not in page and "You are not allowed to arrange the seating." in text(page),
      "members cannot see the master seating")
def menu(session):
    nav = re.search(r'<nav class="orchestra-nav".*?</nav>', session.get(PROJECTS)[1], flags=re.S)
    return text(nav.group(0)) if nav else ""


check("Seating" not in menu(mia) and "Seating" in menu(conductor),
      "the Seating area is in the menu of conductors and organizers only")
_, page = organizer.get(MASTER)
check("data-seating-form" in page and all(n in tray(page) for n in ["Mia", "Max", "Mo", "Olga", "Lena-Marie"])
      and "Carl" not in tray(page) and "Sam" not in tray(page) and "data-seating-voice" not in page,
      "the master seating has every active member and organizer, without voices")
check(cells(page) == 8, "the master seating starts with a seat for everyone")
master = {"mo": "1-1", "mia": "1-2", "organizer": "1-3", "max": "1-4", "lena": "2-4"}
_, page = save_master(organizer, master)
check("The master seating has been saved." in text(page) and seats(0) == master, "organizers arrange the master seating")
_, page = save_master(conductor, master, op="insert_column-1")
check(seats(0)["mo"] == "1-2" and cells(page) == 10, "the master seating has the same editor")
save_master(conductor, master, op="delete_column-5")
status, _ = save_master(mia, master)
check(status == 403 or seats(0) == master, "members cannot change the master seating")

_, page = conductor.get(TAB)
check('value="apply_master"' in page and "Edit master seating" in page, "projects can apply the master seating")
_, page = save(conductor, placement, op="apply_master")
check("The master seating has been applied." in text(page), "the master seating is applied")
check(seats() == {"mia": "1-1", "organizer": "1-2", "max": "1-3", "lena": "2-1"},
      "everyone keeps their row, closed up to the left, without the people who do not take part")
check(cells(page) == 6, "rows and columns nobody sits in are removed")
save(conductor, seats(), organizers=False, op="apply_master")
check(seats() == {"mia": "1-1", "max": "1-2", "lena": "2-1"}, "organizers are left out when switched off")
save(conductor, seats(), op="apply_master")
placement = seats()

# --- Participation changes ----------------------------------------------------------------------
wp_eval(f'global $wpdb; $wpdb->update("{{$wpdb->prefix}}orchestra_projects_participation", ["status" => "declined"],'
        f' ["project_id" => {PROJECT}, "user_id" => {ids["max"]}]);')
phase("parts_assigned")
_, page = mia.get(TAB)
phase("open")
check("Mia" in text(page) and "Max" not in text(page), "people who no longer take part leave the seating")
for login in ["nia", "ole", "pia"]:
    wp("user", "create", login, f"{login}@example.com", "--role=orchestra_member", "--user_pass=pass",
       f"--display_name={login.title()}")
    # New members are asked to respond to open projects by orchestra-projects; they accept.
    wp_eval(f'global $wpdb; $wpdb->update("{{$wpdb->prefix}}orchestra_projects_participation", ["status" => "accepted"],'
            f' ["project_id" => {PROJECT}, "user_id" => {user_id(login)}]);')
_, page = conductor.get(TAB)
check(cells(page) == 6 and all(n in tray(page) for n in ["Nia", "Ole", "Pia"]),
      "the grid does not grow when more people accept; they are not seated yet")
_, page = organizer.get(MASTER)
check(all(n in tray(page) for n in ["Nia", "Ole", "Pia"]), "new members wait in the master seating too")

# --- wp-admin and completed projects ------------------------------------------------------------
status, page = admin.get(f"/wp-admin/admin.php?page=orchestra-projects&project={PROJECT}&tab=seating")
check(status == 200 and "data-seating-form" in page, "administrators arrange the seating in wp-admin")
wp("post", "meta", "update", str(PROJECT), "orchestra_projects_status", "completed")
_, page = conductor.get(TAB)
check("data-seating-form" not in page and "Mia" in text(page), "completed projects show the seating without editing")
wp("post", "meta", "update", str(PROJECT), "orchestra_projects_status", "draft")
_, page = conductor.get(TAB)
check("data-seating-form" in page and 'name="voice[' not in page,
      "while the assignments cannot be changed, the seating shows the voices without selects")
wp("post", "meta", "update", str(PROJECT), "orchestra_projects_status", "open")

# --- Deleting -----------------------------------------------------------------------------------
wp("user", "delete", str(ids["lena"]), "--yes")
check("lena" not in seats(), "deleting a user removes their seat")
export = wp_json(f'echo json_encode(apply_filters("wp_privacy_personal_data_exporters", [])["orchestra-seating"]["callback"]("mia@example.com"));')
check(export and len(export["data"]) == 2 and all(item["data"][1]["value"] == "A" for item in export["data"])
      and "Master seating" in [item["data"][0]["value"] for item in export["data"]],
      "the seats of the project and the master seating are in the personal data export")
wp("post", "delete", str(PROJECT), str(AUTUMN), "--force")
left = wp_json('global $wpdb; echo json_encode([(int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}orchestra_seating_seats WHERE project_id <> 0"),'
               ' (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}orchestra_seating_plans WHERE project_id <> 0")]);')
check(left == [0, 0] and "mia" in seats(0), "deleting the project removes its seating, not the master seating")
wp("plugin", "deactivate", "orchestra-seating")
wp("plugin", "uninstall", "orchestra-seating")
tables = wp_json('global $wpdb; echo json_encode($wpdb->get_col("SHOW TABLES LIKE \'%orchestra_seating%\'"));')
check(tables == [] and wp("option", "get", "orchestra_seating_schema_version") == "", "uninstalling removes the tables")

print()
if failures:
    print(f"{len(failures)} check(s) failed")
    sys.exit(1)
print("All checks passed")
