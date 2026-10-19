/*
 * Stepping through the voices of the pieces, and arranging the seating by drag and drop (mouse,
 * touch and pen) or with the keyboard.
 *
 * Every person carries one voice per piece of the program; the arrows show the voices of the
 * previous or next piece without reloading. Without JavaScript the arrows are links.
 *
 * The form keeps one select per person (`seat[userId]` = "row-column" or "" for no seat). Without
 * JavaScript, people are seated with these selects; with JavaScript they are hidden and kept in
 * sync, so the server always receives the selects.
 *
 * - Drag a person onto a seat or back to "Not seated". Dropping on a taken seat swaps the two.
 * - Keyboard: Enter or Space on a person picks them up, the arrow keys move between the seats
 *   (up is further back), Enter or Space places them, Delete or Backspace unseats them, Escape
 *   cancels.
 * - Changing "Seat organizers" saves right away, because it changes the people shown.
 */
(function () {
    'use strict';

    var EDGE = 60;
    var THRESHOLD = 4;

    function initVoices(container) {
        var cycler = container.querySelector('[data-seating-voices]');
        if (!cycler) {
            return;
        }
        var pieces = JSON.parse(cycler.getAttribute('data-seating-voices'));
        var title = cycler.querySelector('[data-seating-piece]');
        var index = 0;
        pieces.forEach(function (piece, position) {
            if (String(piece.id) === cycler.getAttribute('data-seating-current')) {
                index = position;
            }
        });

        function show(position) {
            index = (position + pieces.length) % pieces.length;
            var id = String(pieces[index].id);
            title.textContent = pieces[index].label;
            Array.prototype.forEach.call(container.querySelectorAll('[data-seating-voice]'), function (voice) {
                voice.hidden = voice.getAttribute('data-seating-voice') !== id;
            });
            // Keeps the piece when the page is reloaded, e.g. after saving.
            if (window.history.replaceState) {
                var url = new URL(window.location.href);
                url.searchParams.set('seating_piece', id);
                window.history.replaceState(null, '', url);
            }
            var redirect = container.querySelector('input[name="redirect_to"]');
            if (redirect) {
                var target = new URL(redirect.value, window.location.href);
                target.searchParams.set('seating_piece', id);
                redirect.value = target.toString();
            }
        }

        Array.prototype.forEach.call(cycler.querySelectorAll('[data-seating-step]'), function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                show(index + parseInt(link.getAttribute('data-seating-step'), 10));
            });
        });
    }

    function init(form) {
        form.classList.add('orchestra-seating--js');
        var tray = form.querySelector('[data-seating-tray] .orchestra-seating__tray-list');
        var cells = Array.prototype.slice.call(form.querySelectorAll('[data-seating-cell]'));
        var scroller = form.querySelector('.orchestra-seating__scroll');
        cells.forEach(function (cell) {
            cell.tabIndex = -1;
        });

        function position(cell) {
            var parts = cell.getAttribute('data-seating-cell').split('-');
            return {row: parseInt(parts[0], 10), column: parseInt(parts[1], 10)};
        }

        function cellAt(row, column) {
            return form.querySelector('[data-seating-cell="' + row + '-' + column + '"]');
        }

        // The first empty seat from the front left, or the first seat if all are taken.
        function firstFreeCell() {
            var sorted = cells.slice().sort(function (a, b) {
                var first = position(a);
                var second = position(b);
                return first.row - second.row || first.column - second.column;
            });
            var free = sorted.filter(function (cell) {
                return !cell.querySelector('[data-seating-person]');
            });
            return free[0] || sorted[0] || null;
        }

        function setSeat(person, cell) {
            var select = form.querySelector('[data-seating-select="' + person.getAttribute('data-seating-person') + '"]');
            if (select) {
                select.value = cell ? cell.getAttribute('data-seating-cell') : '';
            }
        }

        function refresh() {
            cells.forEach(function (cell) {
                cell.classList.toggle('is-empty', !cell.querySelector('[data-seating-person]'));
            });
        }

        // Puts the person on the cell, or back to the tray for null. A person already on the cell
        // takes the place the moved person came from.
        function place(person, cell) {
            var from = person.closest('[data-seating-cell]');
            if (cell === from) {
                return;
            }
            if (cell) {
                var other = cell.querySelector('[data-seating-person]');
                if (other && other !== person) {
                    (from || tray).appendChild(other);
                    setSeat(other, from);
                }
                cell.appendChild(person);
            } else {
                tray.appendChild(person);
            }
            setSeat(person, cell);
            refresh();
        }

        // Saving right away -------------------------------------------------------------------

        Array.prototype.forEach.call(form.querySelectorAll('[data-seating-autosave]'), function (field) {
            field.addEventListener('change', function () {
                var save = form.querySelector('button[name="op"][value="save"]');
                if (form.requestSubmit) {
                    form.requestSubmit(save);
                } else if (save) {
                    save.click();
                }
            });
        });

        // Dragging ----------------------------------------------------------------------------

        var drag = null;

        function target(x, y) {
            var element = document.elementFromPoint(x, y);
            var found = element ? element.closest('[data-seating-cell], [data-seating-tray]') : null;
            return found && form.contains(found) ? found : null;
        }

        function move(event) {
            drag.x = event.clientX;
            drag.y = event.clientY;
            if (!drag.moved && Math.abs(drag.x - drag.startX) + Math.abs(drag.y - drag.startY) < THRESHOLD) {
                return;
            }
            if (!drag.moved) {
                drag.moved = true;
                var box = drag.person.getBoundingClientRect();
                drag.ghost = drag.person.cloneNode(true);
                drag.ghost.removeAttribute('data-seating-person');
                drag.ghost.removeAttribute('tabindex');
                drag.ghost.classList.add('orchestra-seating__ghost');
                drag.ghost.style.width = box.width + 'px';
                drag.ghost.style.height = box.height + 'px';
                // Inside the form, so that it keeps the Orchestra styles.
                form.appendChild(drag.ghost);
                drag.person.classList.add('is-dragging');
                window.requestAnimationFrame(autoScroll);
            }
            drag.ghost.style.left = (drag.x - drag.offsetX) + 'px';
            drag.ghost.style.top = (drag.y - drag.offsetY) + 'px';

            var found = target(drag.x, drag.y);
            if (found !== drag.target) {
                if (drag.target) {
                    drag.target.classList.remove('is-drop-target');
                }
                drag.target = found;
                if (found) {
                    found.classList.add('is-drop-target');
                }
            }
        }

        // Scrolls the page and the grid while the pointer is near their edges.
        function autoScroll() {
            if (!drag || !drag.moved) {
                return;
            }
            var vertical = 0;
            if (drag.y < EDGE) {
                vertical = -Math.ceil((EDGE - drag.y) / 3);
            } else if (drag.y > window.innerHeight - EDGE) {
                vertical = Math.ceil((drag.y - window.innerHeight + EDGE) / 3);
            }
            if (vertical !== 0) {
                window.scrollBy(0, vertical);
            }
            if (scroller) {
                var box = scroller.getBoundingClientRect();
                if (drag.y >= box.top && drag.y <= box.bottom) {
                    if (drag.x < box.left + EDGE) {
                        scroller.scrollLeft -= Math.ceil((box.left + EDGE - drag.x) / 3);
                    } else if (drag.x > box.right - EDGE) {
                        scroller.scrollLeft += Math.ceil((drag.x - box.right + EDGE) / 3);
                    }
                }
            }
            window.requestAnimationFrame(autoScroll);
        }

        function finish(drop) {
            if (drag.moved) {
                if (drop && drag.target) {
                    place(drag.person, drag.target.hasAttribute('data-seating-cell') ? drag.target : null);
                }
                drag.ghost.remove();
                drag.person.classList.remove('is-dragging');
                if (drag.target) {
                    drag.target.classList.remove('is-drop-target');
                }
            }
            drag = null;
        }

        form.addEventListener('pointerdown', function (event) {
            var person = event.target instanceof Element ? event.target.closest('[data-seating-person]') : null;
            if (!person || event.button !== 0) {
                return;
            }
            event.preventDefault();
            person.setPointerCapture(event.pointerId);
            var box = person.getBoundingClientRect();
            drag = {
                person: person,
                startX: event.clientX,
                startY: event.clientY,
                x: event.clientX,
                y: event.clientY,
                offsetX: event.clientX - box.left,
                offsetY: event.clientY - box.top,
                moved: false,
                ghost: null,
                target: null
            };
        });
        form.addEventListener('pointermove', function (event) {
            if (drag) {
                move(event);
            }
        });
        form.addEventListener('pointerup', function () {
            if (drag) {
                finish(true);
            }
        });
        form.addEventListener('pointercancel', function () {
            if (drag) {
                finish(false);
            }
        });

        // Keyboard ----------------------------------------------------------------------------

        var picked = null;
        var cursor = null;

        function setCursor(cell) {
            if (cursor) {
                cursor.classList.remove('is-cursor');
            }
            cursor = cell;
            if (cell) {
                cell.classList.add('is-cursor');
                cell.focus();
            }
        }

        function drop(cell) {
            var person = picked;
            picked.classList.remove('is-picked');
            picked = null;
            setCursor(null);
            if (cell !== undefined) {
                place(person, cell);
            }
            person.focus();
        }

        form.addEventListener('keydown', function (event) {
            var key = event.key;
            if (!picked) {
                var person = event.target instanceof Element ? event.target.closest('[data-seating-person]') : null;
                if (!person) {
                    return;
                }
                if (key === 'Enter' || key === ' ') {
                    event.preventDefault();
                    picked = person;
                    person.classList.add('is-picked');
                    setCursor(person.closest('[data-seating-cell]') || firstFreeCell());
                } else if ((key === 'Delete' || key === 'Backspace') && person.closest('[data-seating-cell]')) {
                    event.preventDefault();
                    place(person, null);
                    person.focus();
                }
                return;
            }

            if (key === 'Escape') {
                event.preventDefault();
                drop(undefined);
            } else if (key === 'Enter' || key === ' ') {
                event.preventDefault();
                drop(cursor);
            } else if (key === 'Delete' || key === 'Backspace') {
                event.preventDefault();
                drop(null);
            } else if (cursor && /^Arrow/.test(key)) {
                event.preventDefault();
                var at = position(cursor);
                var next = cellAt(
                    at.row + (key === 'ArrowUp' ? 1 : key === 'ArrowDown' ? -1 : 0),
                    at.column + (key === 'ArrowRight' ? 1 : key === 'ArrowLeft' ? -1 : 0)
                );
                if (next) {
                    setCursor(next);
                }
            }
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-seating]'), initVoices);
    Array.prototype.forEach.call(document.querySelectorAll('[data-seating-form]'), init);
}());
