
"""
Generic File-Backed Database GUI (Tkinter)
------------------------------------------

This GUI wraps the backend defined in `list.py` and provides a generic,
discipline-agnostic database with CRUD operations for any "context" (table).

Features:
- Choose database directory (default ./database)
- Create / Rename / Delete contexts (tables)
- List entries in a context (IDs with a human-friendly preview)
- Create / Read / Update / Delete entries (JSON editor w/ validation)
- Search entries (by ID or JSON contents)
- Import/Export entries as JSON; Export entire context as CSV
- Toggle "disallow duplicates" (uses global setting in backend Database)

USAGE
-----
1) Ensure `list.py` (from your project) and this script are in the same folder.
2) `python generic_db_gui.py`
3) Optional: Pick a different database directory via "DB ▸ Select Folder..."

TECH NOTES
----------
- This GUI uses only the Python stdlib (tkinter, json, csv, pathlib, uuid).
- It introspects the filesystem to list entry IDs (derived from *.txt filenames)
  since the backend stores one JSON document per file.
- It respects the backend's duplicate policy for new entries: if duplicates
  are disallowed and data is identical to an existing entry (across all contexts),
  add will fail.
"""

from __future__ import annotations

import csv
import json
import os
import sys
import uuid
import shutil
import importlib
from pathlib import Path
import tkinter as tk
from tkinter import ttk, filedialog, messagebox, simpledialog

# Import user's backend (list.py) safely as a module named "filedb"
try:
    filedb = importlib.import_module("list")  # their uploaded backend filename
except Exception as e:
    raise SystemExit(
        "Could not import 'list.py'. Please place this GUI script in the same folder "
        "as list.py (your backend) and run again.\n\nOriginal error: %r" % (e,)
    )

Database = filedb.Database  # type: ignore[attr-defined]

def json_dumps_pretty(obj) -> str:
    return json.dumps(obj, indent=4, ensure_ascii=False)

def parse_json(text: str):
    try:
        return json.loads(text)
    except json.JSONDecodeError as e:
        raise ValueError(f"Invalid JSON: {e}")

def is_atomic(value) -> bool:
    return isinstance(value, (str, int, float, bool)) or value is None

def preview_for_entry(entry: dict) -> str:
    """
    Try to build a short human-friendly preview from a JSON dict.
    - Prefer 'name' or 'title'
    - Otherwise the first string-like field
    - Fallback to a compact key summary
    """
    if not isinstance(entry, dict) or not entry:
        return "(empty)"
    for k in ("name", "title", "label", "id"):
        if k in entry and is_atomic(entry[k]):
            return str(entry[k])[:120]
    # next, first atomic value
    for k, v in entry.items():
        if is_atomic(v):
            return f"{k}: {str(v)[:100]}"
    # fallback
    return "{" + ", ".join(list(entry.keys())[:5]) + "}"

def flatten_for_csv(value):
    """Keep scalars; JSON-encode anything non-scalar for CSV export."""
    if is_atomic(value):
        return value
    return json.dumps(value, ensure_ascii=False)

class GenericDBApp(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("Generic File-Backed Database — GUI")
        self.geometry("1200x720")

        # State
        self.db_dir = Path("./database").resolve()
        self.db = Database(base_path=str(self.db_dir))

        self.current_context_name: str | None = None
        self.current_entry_id: str | None = None

        # Build UI
        self._build_menubar()
        self._build_layout()
        self._refresh_contexts()

    # ---------------- UI Construction ----------------
    def _build_menubar(self):
        menubar = tk.Menu(self)

        dbmenu = tk.Menu(menubar, tearoff=False)
        dbmenu.add_command(label="Select Folder…", command=self._select_db_dir)
        dbmenu.add_separator()
        self.allow_dup_var = tk.BooleanVar(value=bool(self.db.allow_duplicates))
        dbmenu.add_checkbutton(label="Allow Duplicates",
                               variable=self.allow_dup_var,
                               command=self._toggle_duplicates)
        menubar.add_cascade(label="DB", menu=dbmenu)

        ctxmenu = tk.Menu(menubar, tearoff=False)
        ctxmenu.add_command(label="New Context…", command=self._new_context)
        ctxmenu.add_command(label="Rename Context…", command=self._rename_context)
        ctxmenu.add_command(label="Delete Context", command=self._delete_context)
        menubar.add_cascade(label="Context", menu=ctxmenu)

        entrymenu = tk.Menu(menubar, tearoff=False)
        entrymenu.add_command(label="New Entry", command=self._new_entry_blank)
        entrymenu.add_command(label="Duplicate Entry", command=self._duplicate_entry)
        entrymenu.add_command(label="Delete Entry", command=self._delete_entry)
        entrymenu.add_separator()
        entrymenu.add_command(label="Import JSON (list or objects)…", command=self._import_json_entries)
        entrymenu.add_command(label="Export Selected Entry as JSON…", command=self._export_selected_entry_json)
        entrymenu.add_command(label="Export Context as JSON…", command=self._export_context_json)
        entrymenu.add_command(label="Export Context as CSV…", command=self._export_context_csv)
        menubar.add_cascade(label="Entries", menu=entrymenu)

        self.config(menu=menubar)

    def _build_layout(self):
        # Main paned layout
        paned = ttk.Panedwindow(self, orient=tk.HORIZONTAL)
        paned.pack(fill=tk.BOTH, expand=True)

        # Left: contexts list
        left_frame = ttk.Frame(paned, padding=8)
        paned.add(left_frame, weight=1)

        ttk.Label(left_frame, text="Contexts").pack(anchor="w")
        self.contexts_list = tk.Listbox(left_frame, height=12, exportselection=False)
        self.contexts_list.pack(fill=tk.BOTH, expand=True)
        self.contexts_list.bind("<<ListboxSelect>>", lambda e: self._on_context_selected())

        ctx_btns = ttk.Frame(left_frame)
        ctx_btns.pack(fill=tk.X, pady=(6,0))
        ttk.Button(ctx_btns, text="New", command=self._new_context).pack(side=tk.LEFT)
        ttk.Button(ctx_btns, text="Rename", command=self._rename_context).pack(side=tk.LEFT, padx=4)
        ttk.Button(ctx_btns, text="Delete", command=self._delete_context).pack(side=tk.LEFT)

        # Middle: entries table with search
        mid_frame = ttk.Frame(paned, padding=8)
        paned.add(mid_frame, weight=3)

        search_bar = ttk.Frame(mid_frame)
        search_bar.pack(fill=tk.X)
        ttk.Label(search_bar, text="Search:").pack(side=tk.LEFT)
        self.search_var = tk.StringVar()
        self.search_var.trace_add("write", lambda *_: self._refresh_entries())
        ttk.Entry(search_bar, textvariable=self.search_var).pack(side=tk.LEFT, fill=tk.X, expand=True, padx=6)
        ttk.Button(search_bar, text="Clear", command=lambda: self.search_var.set("")).pack(side=tk.LEFT)

        self.entries_tree = ttk.Treeview(mid_frame, columns=("id", "preview"), show="headings", height=20)
        self.entries_tree.heading("id", text="ID")
        self.entries_tree.heading("preview", text="Preview")
        self.entries_tree.column("id", width=260, anchor="w")
        self.entries_tree.column("preview", width=420, anchor="w")
        self.entries_tree.pack(fill=tk.BOTH, expand=True, pady=(6,0))
        self.entries_tree.bind("<<TreeviewSelect>>", lambda e: self._on_entry_selected())

        entry_btns = ttk.Frame(mid_frame)
        entry_btns.pack(fill=tk.X, pady=(6,0))
        ttk.Button(entry_btns, text="New Entry", command=self._new_entry_blank).pack(side=tk.LEFT)
        ttk.Button(entry_btns, text="Duplicate", command=self._duplicate_entry).pack(side=tk.LEFT, padx=4)
        ttk.Button(entry_btns, text="Delete", command=self._delete_entry).pack(side=tk.LEFT, padx=4)
        ttk.Button(entry_btns, text="Export JSON", command=self._export_selected_entry_json).pack(side=tk.LEFT, padx=12)
        ttk.Button(entry_btns, text="Export Context JSON", command=self._export_context_json).pack(side=tk.LEFT)
        ttk.Button(entry_btns, text="Export Context CSV", command=self._export_context_csv).pack(side=tk.LEFT, padx=4)

        # Right: JSON editor
        right_frame = ttk.Frame(paned, padding=8)
        paned.add(right_frame, weight=3)

        ttk.Label(right_frame, text="JSON Editor").pack(anchor="w")
        self.editor = tk.Text(right_frame, wrap="none", undo=True, height=30)
        self.editor.pack(fill=tk.BOTH, expand=True)

        save_bar = ttk.Frame(right_frame)
        save_bar.pack(fill=tk.X, pady=(6,0))
        ttk.Button(save_bar, text="Save (Create/Update)", command=self._save_editor).pack(side=tk.LEFT)
        self.status_var = tk.StringVar(value="Ready.")
        ttk.Label(save_bar, textvariable=self.status_var, anchor="e").pack(side=tk.RIGHT)

    # ---------------- Helpers ----------------
    def _select_db_dir(self):
        newdir = filedialog.askdirectory(title="Select database folder…")
        if not newdir:
            return
        self.db_dir = Path(newdir).resolve()
        self._reload_db()

    def _reload_db(self):
        # Preserve duplicate flag
        allow = bool(self.db.allow_duplicates)
        self.db = Database(base_path=str(self.db_dir), allow_duplicates=allow)
        self.current_context_name = None
        self.current_entry_id = None
        self._refresh_contexts()
        self._clear_entries()
        self._clear_editor()
        self.status_var.set(f"Opened DB at: {self.db_dir}")

    def _toggle_duplicates(self):
        flag = bool(self.allow_dup_var.get())
        self.db.set_allow_duplicates(flag)
        self.status_var.set(f"Allow duplicates: {flag}")

    # ---------------- Context ops ----------------
    def _refresh_contexts(self):
        # list contexts by directories in db.base_path
        self.contexts_list.delete(0, tk.END)
        names = sorted([p.name for p in Path(self.db.base_path).iterdir() if p.is_dir()])
        for n in names:
            self.contexts_list.insert(tk.END, n)
        # reselect current if exists
        if self.current_context_name in names:
            idx = names.index(self.current_context_name)
            self.contexts_list.selection_clear(0, tk.END)
            self.contexts_list.selection_set(idx)
            self.contexts_list.see(idx)

    def _get_selected_context_name(self):
        sel = self.contexts_list.curselection()
        if not sel:
            return None
        return self.contexts_list.get(sel[0])

    def _on_context_selected(self):
        name = self._get_selected_context_name()
        self.current_context_name = name
        self._refresh_entries()
        self._clear_editor()

    def _new_context(self):
        name = simpledialog.askstring("New Context", "Context name:")
        if not name:
            return
        if self.db.get_context(name):
            messagebox.showerror("Exists", f"Context '{name}' already exists.")
            return
        self.db.create_context(name)
        self._refresh_contexts()
        self.status_var.set(f"Created context '{name}'.")

    def _rename_context(self):
        old = self._get_selected_context_name()
        if not old:
            messagebox.showinfo("Select Context", "Pick a context to rename.")
            return
        new = simpledialog.askstring("Rename Context", f"New name for '{old}':")
        if not new or new == old:
            return
        old_path = Path(self.db.base_path) / old
        new_path = Path(self.db.base_path) / new
        if new_path.exists():
            messagebox.showerror("Exists", f"Context '{new}' already exists.")
            return
        try:
            os.rename(old_path, new_path)
        except Exception as e:
            messagebox.showerror("Rename Failed", str(e))
            return
        # Rebuild DB to refresh context map
        self._reload_db()
        # Select the new one
        self.current_context_name = new
        self._refresh_contexts()
        self.status_var.set(f"Renamed context '{old}' -> '{new}'.")

    def _delete_context(self):
        name = self._get_selected_context_name()
        if not name:
            messagebox.showinfo("Select Context", "Pick a context to delete.")
            return
        if not messagebox.askyesno("Delete Context", f"Delete context '{name}' and ALL its entries?"):
            return
        self.db.delete_context(name)
        self._refresh_contexts()
        self._clear_entries()
        self._clear_editor()
        self.status_var.set(f"Deleted context '{name}'.")

    # ---------------- Entry ops ----------------
    def _context_path(self, ctx_name: str) -> Path:
        return Path(self.db.base_path) / ctx_name

    def _list_entry_ids(self, ctx_name: str) -> list[str]:
        ctxp = self._context_path(ctx_name)
        return sorted([p.stem for p in ctxp.glob("*.txt")])

    def _load_entry_by_id(self, ctx_name: str, entry_id: str):
        ctxp = self._context_path(ctx_name)
        fp = ctxp / f"{entry_id}.txt"
        if not fp.exists():
            return None
        try:
            return json.loads(fp.read_text(encoding="utf-8"))
        except Exception:
            return None

    def _refresh_entries(self):
        self.entries_tree.delete(*self.entries_tree.get_children())
        ctx = self.current_context_name
        if not ctx:
            return
        ids = self._list_entry_ids(ctx)
        q = self.search_var.get().strip().lower()
        for eid in ids:
            data = self._load_entry_by_id(ctx, eid) or {}
            prev = preview_for_entry(data)
            row_text = f"{eid} {json_dumps_pretty(data)[:200]}".lower()
            if q and q not in row_text:
                continue
            self.entries_tree.insert("", tk.END, iid=eid, values=(eid, prev))

    def _clear_entries(self):
        self.entries_tree.delete(*self.entries_tree.get_children())

    def _on_entry_selected(self):
        sel = self.entries_tree.selection()
        if not sel:
            self.current_entry_id = None
            return
        self.current_entry_id = sel[0]
        ctx = self.current_context_name
        if not ctx:
            return
        data = self._load_entry_by_id(ctx, self.current_entry_id) or {}
        self._set_editor_json(data)

    def _new_entry_blank(self):
        self.current_entry_id = None
        self._set_editor_json({})
        self.editor.focus_set()
        self.status_var.set("New entry: compose JSON then click Save.")

    def _duplicate_entry(self):
        ctx = self.current_context_name
        eid = self.current_entry_id
        if not ctx or not eid:
            messagebox.showinfo("Select Entry", "Pick an entry to duplicate.")
            return
        data = self._load_entry_by_id(ctx, eid)
        if data is None:
            messagebox.showerror("Error", "Could not load selected entry.")
            return
        # Attempt to add via backend (respects duplicate policy)
        ctx_obj = self.db.get_context(ctx)
        if not ctx_obj:
            messagebox.showerror("Error", "Context not found in backend.")
            return
        new_id = ctx_obj.add_entry(data)
        if not new_id:
            messagebox.showwarning("Duplicate Disallowed", "Add failed due to duplicate restriction (or error).")
            return
        self._refresh_entries()
        # select new row
        self.entries_tree.selection_set(new_id)
        self.entries_tree.see(new_id)
        self.current_entry_id = new_id
        self._set_editor_json(data)
        self.status_var.set(f"Duplicated entry: {new_id}")

    def _delete_entry(self):
        ctx = self.current_context_name
        eid = self.current_entry_id
        if not ctx or not eid:
            messagebox.showinfo("Select Entry", "Pick an entry to delete.")
            return
        if not messagebox.askyesno("Delete Entry", f"Delete entry {eid}?"):
            return
        ctx_obj = self.db.get_context(ctx)
        if not ctx_obj:
            messagebox.showerror("Error", "Context not found in backend.")
            return
        ok = ctx_obj.delete_entry(eid)
        if not ok:
            messagebox.showerror("Delete Failed", "Could not delete entry (see console).")
            return
        self._refresh_entries()
        self._clear_editor()
        self.status_var.set(f"Deleted entry: {eid}")
        self.current_entry_id = None

    def _save_editor(self):
        ctx = self.current_context_name
        if not ctx:
            messagebox.showinfo("Select Context", "Pick a context first (left panel).")
            return
        try:
            data = parse_json(self.editor.get("1.0", tk.END))
        except ValueError as e:
            messagebox.showerror("Invalid JSON", str(e))
            return
        ctx_obj = self.db.get_context(ctx)
        if not ctx_obj:
            messagebox.showerror("Error", "Context not found in backend.")
            return

        if self.current_entry_id:
            # Update existing
            ok = ctx_obj.update_entry(self.current_entry_id, data)
            if not ok:
                messagebox.showerror("Update Failed", "Could not update entry (see console).")
                return
            self.status_var.set(f"Updated entry: {self.current_entry_id}")
        else:
            # Create new via add_entry (backend assigns ID)
            new_id = ctx_obj.add_entry(data)
            if not new_id:
                messagebox.showwarning("Duplicate Disallowed", "Add failed due to duplicate restriction (or error).")
                return
            self.current_entry_id = new_id
            self.status_var.set(f"Created new entry: {new_id}")
        self._refresh_entries()

    def _set_editor_json(self, obj):
        self.editor.delete("1.0", tk.END)
        self.editor.insert("1.0", json_dumps_pretty(obj))

    def _clear_editor(self):
        self.editor.delete("1.0", tk.END)
        self.current_entry_id = None

    # ---------------- Import / Export ----------------
    def _import_json_entries(self):
        ctx = self.current_context_name
        if not ctx:
            messagebox.showinfo("Select Context", "Pick a context first.")
            return
        fp = filedialog.askopenfilename(
            title="Import JSON (array or object-per-line)",
            filetypes=[("JSON files", "*.json *.jsonl"), ("All files", "*.*")],
        )
        if not fp:
            return
        ctx_obj = self.db.get_context(ctx)
        if not ctx_obj:
            messagebox.showerror("Error", "Context not found in backend.")
            return

        count = 0
        try:
            text = Path(fp).read_text(encoding="utf-8").strip()
            # Try array form first
            try:
                data = json.loads(text)
                if isinstance(data, dict):
                    data = [data]  # single object -> list
                if not isinstance(data, list):
                    raise ValueError("Top-level must be an object or array.")
                for item in data:
                    if not isinstance(item, dict):
                        continue
                    if ctx_obj.add_entry(item):
                        count += 1
            except json.JSONDecodeError:
                # Fallback to jsonlines (one object per line)
                for line in text.splitlines():
                    if not line.strip():
                        continue
                    try:
                        obj = json.loads(line)
                    except Exception:
                        continue
                    if isinstance(obj, dict) and ctx_obj.add_entry(obj):
                        count += 1
        except Exception as e:
            messagebox.showerror("Import Failed", str(e))
            return

        self._refresh_entries()
        messagebox.showinfo("Import Complete", f"Imported {count} entries.")

    def _export_selected_entry_json(self):
        ctx = self.current_context_name
        eid = self.current_entry_id
        if not ctx or not eid:
            messagebox.showinfo("Select Entry", "Pick an entry first.")
            return
        data = self._load_entry_by_id(ctx, eid)
        if data is None:
            messagebox.showerror("Error", "Could not load entry.")
            return
        fp = filedialog.asksaveasfilename(
            title="Export Entry as JSON",
            defaultextension=".json",
            initialfile=f"{eid}.json",
            filetypes=[("JSON", "*.json")],
        )
        if not fp:
            return
        Path(fp).write_text(json_dumps_pretty(data), encoding="utf-8")
        self.status_var.set(f"Exported entry JSON: {fp}")

    def _export_context_json(self):
        ctx = self.current_context_name
        if not ctx:
            messagebox.showinfo("Select Context", "Pick a context first.")
            return
        ids = self._list_entry_ids(ctx)
        payload = []
        for eid in ids:
            obj = self._load_entry_by_id(ctx, eid)
            if obj is not None:
                payload.append({"id": eid, "data": obj})
        fp = filedialog.asksaveasfilename(
            title="Export Context as JSON",
            defaultextension=".json",
            initialfile=f"{ctx}.json",
            filetypes=[("JSON", "*.json")],
        )
        if not fp:
            return
        Path(fp).write_text(json_dumps_pretty(payload), encoding="utf-8")
        self.status_var.set(f"Exported context JSON: {fp}")

    def _export_context_csv(self):
        ctx = self.current_context_name
        if not ctx:
            messagebox.showinfo("Select Context", "Pick a context first.")
            return
        ids = self._list_entry_ids(ctx)
        rows = []
        all_keys = set()
        for eid in ids:
            obj = self._load_entry_by_id(ctx, eid) or {}
            rows.append((eid, obj))
            all_keys.update(obj.keys())
        all_keys = ["id"] + sorted(all_keys)

        fp = filedialog.asksaveasfilename(
            title="Export Context as CSV",
            defaultextension=".csv",
            initialfile=f"{ctx}.csv",
            filetypes=[("CSV", "*.csv")],
        )
        if not fp:
            return
        with open(fp, "w", newline="", encoding="utf-8") as f:
            writer = csv.writer(f)
            writer.writerow(all_keys)
            for eid, obj in rows:
                row = [eid]
                for k in all_keys[1:]:
                    row.append(flatten_for_csv(obj.get(k)))
                writer.writerow(row)
        self.status_var.set(f"Exported context CSV: {fp}")


if __name__ == "__main__":
    app = GenericDBApp()
    app.mainloop()
