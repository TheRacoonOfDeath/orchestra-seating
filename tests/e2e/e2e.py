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
    return html.unescape(re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", page)))



ids = {login: user_id(login) for login in ["admin", "organizer", "conductor", "mia", "max", "mo", "lena", "sam"]}
PROJECT = SEED["project"]
PAGES = wp_json('echo json_encode(["projects" => Orchestra\\Core\\Ui\\Pages::url("orchestra_projects")]);')
PROJECTS = PAGES["projects"].replace(BASE, "")
TAB = f"{PROJECTS}&orchestra_project={PROJECT}&orchestra_tab=seating"
OVERVIEW = f"{PROJECTS}&orchestra_project={PROJECT}"

admin, organizer, conductor = Session("admin", "admin"), Session("organizer"), Session("conductor")
mia, sam = Session("mia"), Session("sam")


def seats():
    rows = wp_json('global $wpdb; echo json_encode($wpdb->get_results("SELECT user_id, seat_row, seat_column FROM'
                   ' {$wpdb->prefix}orchestra_seating_seats ORDER BY seat_row, seat_column", ARRAY_A));') or []
    names = {v: k for k, v in ids.items()}
    return {names.get(int(r["user_id"]), r["user_id"]): f'{r["seat_row"]}-{r["seat_column"]}' for r in rows}


def form_of(page):
    return next((f for f in re.findall(r"<form.*?</form>", page, flags=re.S) if "orchestra_seating_save" in f), "")


def save(session, placement, op="save", piece=None, organizers=True, page=None):
    """Submits the seating form like a browser: every select, the piece and the organizer switch."""
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
    if piece is not None:
        data["piece"] = str(piece)
    else:
        selected = re.search(r'<option value="(\d+)" selected', form)
        if selected:
            data["piece"] = selected.group(1)
    if organizers:
        data["seat_organizers"] = "1"
    return session.post("/wp-admin/admin-post.php", data)


def cells(page):
    return len(re.findall(r"data-seating-cell=", page))


def tray(page):
    part = page.split("data-seating-tray", 1)[1].split("</section>", 1)[0] if "data-seating-tray" in page else ""
    return text(part)


# --- Plugin dependencies ----------------------------------------------------------------------
circular = wp_json('WP_Plugin_Dependencies::initialize(); echo json_encode(array_values(array_filter(array_keys(get_plugins()),'
                   ' fn ($p) => WP_Plugin_Dependencies::has_circular_dependency($p))));')
check(circular == [], "WordPress finds no circular plugin dependencies")

# --- Capabilities and tab ---------------------------------------------------------------------
caps = wp_json('echo json_encode(array_map(fn ($r) => array_keys(array_filter(get_role($r)->capabilities)),'
               ' ["orchestra_member", "orchestra_conductor", "orchestra_organizer"]));')
check("orchestra_seating_plan_read" in caps[0] and "orchestra_seating_plan_manage" not in caps[0],
      "members may only see the seating")
check(all("orchestra_seating_plan_manage" in c for c in caps[1:]), "conductors and organizers arrange the seating")

_, page = mia.get(OVERVIEW)
check("orchestra_tab=seating" not in page, "members see no seating before it is arranged")
_, page = conductor.get(TAB)
check("data-seating-form" in page and "Not seated" in page, "conductors open the seating editor")
check(all(name in tray(page) for name in ["Mia", "Max", "Olga", "Lena-Marie"]) and "Carl" not in tray(page)
      and "Mo" not in tray(page).split(), "accepted members and organizers are to be seated, conductors and those who declined are not")
check(cells(page) == 4, "the grid starts with a seat for everyone")

# --- Arranging ----------------------------------------------------------------------------------
_, page = save(conductor, {"mia": "1-1", "max": "1-2", "organizer": "1-3", "lena": "1-4"}, piece=SEED["pirates"])
check("The seating has been saved." in text(page) and seats() == {"mia": "1-1", "max": "1-2", "organizer": "1-3", "lena": "1-4"},
      "conductors seat people")
_, page = save(organizer, {"mia": "1-2", "max": "1-1", "organizer": "1-3", "lena": "1-4"})
check(seats()["mia"] == "1-2" and seats()["max"] == "1-1", "organizers rearrange the seating")
_, page = save(conductor, {"mia": "1-1", "max": "1-1"})
check("Two people cannot sit on the same seat." in text(page) and seats()["mia"] == "1-2", "a seat holds one person")
_, page = save(conductor, {"mia": "5-1"})
check("outside the grid" in text(page), "seats outside the grid are rejected")

# --- Viewing ------------------------------------------------------------------------------------
_, page = mia.get(OVERVIEW)
check("orchestra_tab=seating" in page, "members see the seating once it is arranged")
_, page = mia.get(TAB)
plain = text(page)
check("data-seating-form" not in page and "Mia Horn 1" in plain and "Max Horn 2" in plain and "Olga —" in plain
      and "Voices in Pirates of the Caribbean" in plain, "members see names and voices, without editing")
check("Lena-Marie Sophie von Hohenzollern-Sigmaringen" in plain, "long names are shown")
save(conductor, {"mia": "1-2", "max": "1-1", "organizer": "1-3", "lena": "1-4"}, piece=SEED["dance"])
_, page = mia.get(TAB)
check("Mia Violin 1" in text(page) and "Max —" in text(page), "the voices follow the chosen piece")
status, _ = save(mia, {"mia": "1-1"}, page=page)
check(status == 403 or "orchestra_seating_save" not in form_of(page), "members cannot change the seating")
status, _ = sam.post("/wp-admin/admin-post.php", {"action": "orchestra_seating_save", "project": str(PROJECT)})
check(status in (403, 400) or seats()["mia"] == "1-2", "others cannot change the seating")

# --- Grid ---------------------------------------------------------------------------------------
placement = {"mia": "1-2", "max": "1-1", "organizer": "1-3", "lena": "1-4"}
save(conductor, placement, op="add_row")
_, page = conductor.get(TAB)
check(cells(page) == 8, "rows can be added")
save(conductor, placement, op="add_column")
_, page = conductor.get(TAB)
check(cells(page) == 10, "columns can be added")
_, page = save(conductor, {"mia": "2-5", "max": "1-1", "organizer": "1-3", "lena": "1-4"}, op="remove_row")
check("Only an empty last row or column can be removed." in text(page) and cells(page) == 10,
      "rows with people cannot be removed")
save(conductor, placement, op="remove_row")
save(conductor, placement, op="remove_column")
_, page = conductor.get(TAB)
check(cells(page) == 4, "empty rows and columns can be removed")
_, page = save(conductor, placement, op="remove_column")
check("The grid needs a seat for everyone." in text(page), "the grid keeps a seat for everyone")

# --- Organizers ---------------------------------------------------------------------------------
save(conductor, placement, organizers=False)
_, page = conductor.get(TAB)
check("organizer" not in seats() and "Olga" not in text(page).split("Not seated", 1)[1], "organizers can be left out")
save(conductor, placement, organizers=True)
_, page = conductor.get(TAB)
check("Olga" in tray(page), "organizers are seated again when switched on")

# --- Participation changes ----------------------------------------------------------------------
wp_eval(f'global $wpdb; $wpdb->update("{{$wpdb->prefix}}orchestra_projects_participation", ["status" => "declined"],'
        f' ["project_id" => {PROJECT}, "user_id" => {ids["max"]}]);')
_, page = mia.get(TAB)
check("Max" not in text(page), "people who no longer take part leave the seating")
for login in ["nia", "ole", "pia"]:
    wp("user", "create", login, f"{login}@example.com", "--role=orchestra_member", "--user_pass=pass",
       f"--display_name={login.title()}")
    # New members are asked to respond to open projects by orchestra-projects; they accept.
    wp_eval(f'global $wpdb; $wpdb->update("{{$wpdb->prefix}}orchestra_projects_participation", ["status" => "accepted"],'
            f' ["project_id" => {PROJECT}, "user_id" => {user_id(login)}]);')
_, page = conductor.get(TAB)
check(cells(page) >= 7 and all(n in tray(page) for n in ["Nia", "Ole", "Pia"]),
      "rows are added when more people accept")

# --- wp-admin and completed projects ------------------------------------------------------------
status, page = admin.get(f"/wp-admin/admin.php?page=orchestra-projects&project={PROJECT}&tab=seating")
check(status == 200 and "data-seating-form" in page, "administrators arrange the seating in wp-admin")
wp("post", "meta", "update", str(PROJECT), "orchestra_projects_status", "completed")
_, page = conductor.get(TAB)
check("data-seating-form" not in page and "Mia" in text(page), "completed projects show the seating without editing")
wp("post", "meta", "update", str(PROJECT), "orchestra_projects_status", "open")

# --- Deleting -----------------------------------------------------------------------------------
wp("user", "delete", str(ids["lena"]), "--yes")
check("lena" not in seats(), "deleting a user removes their seat")
export = wp_json(f'echo json_encode(apply_filters("wp_privacy_personal_data_exporters", [])["orchestra-seating"]["callback"]("mia@example.com"));')
check(export and export["data"] and export["data"][0]["data"][1]["value"] == "1", "the seat is in the personal data export")
wp("post", "delete", str(PROJECT), "--force")
left = wp_json('global $wpdb; echo json_encode([(int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}orchestra_seating_seats"),'
               ' (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}orchestra_seating_plans")]);')
check(left == [0, 0], "deleting the project removes its seating")
wp("plugin", "deactivate", "orchestra-seating")
wp("plugin", "uninstall", "orchestra-seating")
tables = wp_json('global $wpdb; echo json_encode($wpdb->get_col("SHOW TABLES LIKE \'%orchestra_seating%\'"));')
check(tables == [] and wp("option", "get", "orchestra_seating_schema_version") == "", "uninstalling removes the tables")

print()
if failures:
    print(f"{len(failures)} check(s) failed")
    sys.exit(1)
print("All checks passed")
