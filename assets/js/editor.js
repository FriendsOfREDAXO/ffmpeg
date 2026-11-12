/**
 * FFmpeg – Schnitt-Editor (Video-Trimmer) und Untertitel-Editor.
 *
 * Nach dem Vorbild des AddOns video_editor (REDAXO 6), für das REDAXO-5-Backend (Bootstrap 3) und ergänzt um alle
 * Funktionen des bisherigen Trimmers: Ton entfernen, Ping-Pong-Schleife (und -Vorschau), Schleifen-Vorschau,
 * Bereich testen, Poster-Bild, Strg+S / Strg+E.
 *
 * Einstieg: [data-ffmpeg-cut] und [data-ffmpeg-tracks] mit JSON-Konfiguration (file, i18n, …). Alle Aufrufe gehen
 * an die Backend-API ffmpeg_editor mit CSRF-Token (rex.ffmpeg_editor, siehe boot.php).
 */
(function () {
    'use strict';

    var el = function (tag, attrs) {
        var node = document.createElement(tag);
        attrs = attrs || {};
        Object.keys(attrs).forEach(function (k) {
            var v = attrs[k];
            if (v === null || v === undefined || v === false) {
                return;
            }
            if (k === 'class') {
                node.className = v;
            } else if (k === 'text') {
                node.textContent = v;
            } else {
                node.setAttribute(k, v === true ? '' : v);
            }
        });
        for (var i = 2; i < arguments.length; i++) {
            [].concat(arguments[i]).forEach(function (c) {
                if (c !== null && c !== undefined && c !== false) {
                    node.append(c);
                }
            });
        }
        return node;
    };
    var icon = function (name) {
        return el('i', { class: 'rex-icon ' + name, 'aria-hidden': 'true' });
    };
    var fill = function (text, a, b, c) {
        var args = [a, b, c];
        return String(text).replace(/\{(\d)\}/g, function (_, i) {
            return args[Number(i)] !== undefined ? args[Number(i)] : '';
        });
    };
    var time = function (s) {
        s = Math.max(0, Math.floor(s));
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = String(s % 60).padStart(2, '0');
        return h ? h + ':' + String(m).padStart(2, '0') + ':' + sec : m + ':' + sec;
    };
    // genaue Zeit mit Zehntelsekunden, z. B. 1:05.3
    var clock = function (s) {
        var ms = Math.max(0, Math.round(s * 1000));
        return time(Math.floor(ms / 1000)) + '.' + Math.floor((ms % 1000) / 100);
    };
    var alertBox = function (variant, text) {
        return el('div', { class: 'alert alert-' + variant, role: 'alert' }, text);
    };

    var api = function (file, action, extra) {
        var params = Object.assign({}, (window.rex && window.rex.ffmpeg_editor) || { 'rex-api-call': 'ffmpeg_editor' });
        var body = new FormData();
        body.append('action', action);
        body.append('file', file);
        Object.keys(extra || {}).forEach(function (k) {
            body.append(k, extra[k]);
        });
        return fetch('index.php?' + new URLSearchParams(params).toString(), {
            method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' },
        }).then(function (res) {
            return res.json().catch(function () {
                return { ok: false };
            });
        }).then(function (json) {
            if (!json.ok) {
                throw new Error(json.error || 'Fehler');
            }
            return json;
        });
    };

    var btn = function (label, iconName, attrs) {
        var b = el('button', Object.assign({ type: 'button', class: 'btn btn-default btn-sm' }, attrs || {}));
        if (iconName) {
            b.append(icon(iconName));
        }
        if (label) {
            b.append((iconName ? ' ' : '') + label);
        }
        return b;
    };

    // ============================================================================================== Schnitt-Editor

    var initCut = function (root) {
        if (root.dataset.ffmpegReady) {
            return;
        }
        root.dataset.ffmpegReady = '1';
        var cfg = JSON.parse(root.dataset.ffmpegCut);
        var t = cfg.i18n;
        root.replaceChildren(el('p', { class: 'text-muted' }, icon('fa-spinner fa-spin'), ' …'));
        api(cfg.file, 'info').then(function (data) {
            buildCut(root, cfg, t, data);
        }).catch(function (e) {
            root.replaceChildren(alertBox('danger', e.message));
        });
    };

    var buildCut = function (root, cfg, t, data) {
        var duration = data.duration || 0;
        var frame = 1 / (data.fps || 25);
        var MIN = 0.1;
        var segs = [[0, duration]];
        var sel = 0;
        var history = [];
        var previewing = false;
        var pingPong = null;
        var keyframes = [];

        var video = el('video', { class: 'ffmpeg-cut-video', src: data.stream, preload: 'auto', playsinline: true });
        var strip = el('div', { class: 'ffmpeg-cut-strip', 'aria-hidden': 'true' });
        var ticks = el('div', { class: 'ffmpeg-cut-ticks', 'aria-hidden': 'true' });
        var layer = el('div', { class: 'ffmpeg-cut-layer' });
        var head = el('div', { class: 'ffmpeg-cut-head', 'aria-hidden': 'true' });
        var timeline = el('div', {
            class: 'ffmpeg-cut-timeline', role: 'slider', tabindex: '0', 'aria-label': t.timeline,
            'aria-valuemin': '0', 'aria-valuemax': String(Math.round(duration * 10) / 10),
        }, strip, ticks, layer, head);
        var now = el('output', { class: 'ffmpeg-cut-now', text: clock(0) + ' / ' + clock(duration) });
        var lengthInfo = el('p', { class: 'ffmpeg-cut-length', 'aria-live': 'polite' });
        var snapNote = el('p', { class: 'ffmpeg-cut-note', role: 'status', hidden: true });

        // ---- Bedienelemente
        var playBtn = btn('', 'fa-play', { 'data-cut': 'toggle', 'aria-label': t.play_pause, title: t.play_pause + ' (' + t.key_space + ')', class: 'btn btn-primary btn-sm' });
        var removeBtn = btn(t.remove, 'fa-trash', { 'data-cut': 'remove', 'aria-keyshortcuts': 'Delete' });
        var undoBtn = btn(t.undo, 'fa-undo', { 'data-cut': 'undo', 'aria-keyshortcuts': 'Control+Z' });
        var resultBtn = btn(t.play_result, 'fa-forward', { 'data-cut': 'result', class: 'btn btn-success btn-sm' });
        var pingBtn = btn(t.pingpong_test, 'fa-exchange', { 'data-cut': 'pingpong', class: 'btn btn-warning btn-sm' });
        var loopPreview = el('input', { type: 'checkbox', class: 'ffmpeg-cut-loop-preview' });
        var controls = el('div', { class: 'ffmpeg-cut-controls' },
            el('div', { class: 'ffmpeg-cut-group' },
                playBtn,
                btn('−5 s', null, { 'data-step': '-5' }), btn('−1 s', null, { 'data-step': '-1' }),
                btn('−1 ' + t.frame, null, { 'data-step': String(-frame) }),
                now,
                btn('+1 ' + t.frame, null, { 'data-step': String(frame) }),
                btn('+1 s', null, { 'data-step': '1' }), btn('+5 s', null, { 'data-step': '5' })
            ),
            el('div', { class: 'ffmpeg-cut-group' },
                btn(t.split, 'fa-scissors', { 'data-cut': 'split', 'aria-keyshortcuts': 'S' }),
                btn(t.set_in, 'fa-sign-in', { 'data-cut': 'in', 'aria-keyshortcuts': 'I Control+S', title: t.set_in + ' (I, ' + t.key_ctrl + '+S)' }),
                btn(t.set_out, 'fa-sign-out', { 'data-cut': 'out', 'aria-keyshortcuts': 'O Control+E', title: t.set_out + ' (O, ' + t.key_ctrl + '+E)' }),
                removeBtn, undoBtn,
                btn(t.reset, null, { 'data-cut': 'reset' })
            ),
            el('div', { class: 'ffmpeg-cut-group' },
                resultBtn, pingBtn,
                el('label', { class: 'checkbox-inline ffmpeg-cut-loop-label' }, loopPreview, ' ' + t.loop_preview)
            )
        );

        // ---- genaue Zeiten des gewählten Abschnitts (wie die Start-/Endfelder des bisherigen Trimmers)
        var uid = 'ffmpeg-cut-' + Math.random().toString(36).slice(2, 8);
        var startInput = el('input', { type: 'number', class: 'form-control', id: uid + '-start', step: '0.1', min: '0' });
        var endInput = el('input', { type: 'number', class: 'form-control', id: uid + '-end', step: '0.1', min: '0' });
        var selTitle = el('strong', {});
        var selBox = el('div', { class: 'ffmpeg-cut-sel' },
            selTitle,
            el('label', { for: uid + '-start' }, t.start_time),
            startInput,
            el('label', { for: uid + '-end' }, t.end_time),
            endInput
        );

        // ---- Optionen
        var ext = (data.extension || '').toLowerCase();
        var radio = function (name, value, label, help, checked) {
            return el('div', { class: 'radio' }, el('label', {},
                el('input', { type: 'radio', name: name + uid, value: value, checked: checked }), ' ' + label,
                help ? el('small', { class: 'help-block', text: help }) : null
            ));
        };
        var method = el('fieldset', { class: 'ffmpeg-cut-fieldset' }, el('legend', { text: t.method }),
            radio('method', '0', t.fast, t.fast_text, true),
            radio('method', '1', t.exact, t.exact_text, false)
        );
        var removeAudio = el('input', { type: 'checkbox', name: 'remove_audio' });
        var createLoop = el('input', { type: 'checkbox', name: 'create_loop' });
        var extras = el('fieldset', { class: 'ffmpeg-cut-fieldset' }, el('legend', { text: t.options }),
            el('div', { class: 'checkbox' }, el('label', {}, removeAudio, ' ' + t.remove_audio,
                el('small', { class: 'help-block', text: data.audio ? t.remove_audio_help : t.no_audio }))),
            el('div', { class: 'checkbox' }, el('label', {}, createLoop, ' ' + t.create_loop,
                el('small', { class: 'help-block', text: t.create_loop_help })))
        );
        var mode = el('fieldset', { class: 'ffmpeg-cut-fieldset' }, el('legend', { text: t.mode }),
            radio('mode', 'version', t.mode_version, t.mode_version_text, true),
            radio('mode', 'replace', t.mode_replace, t.mode_replace_text, false)
        );
        var isExact = function () {
            return method.querySelector('input:checked').value === '1' || createLoop.checked;
        };
        var syncOptions = function () {
            // Ping-Pong: nur bildgenau und ohne Ton (wie bisher)
            method.querySelector('input[value="0"]').disabled = createLoop.checked;
            if (createLoop.checked) {
                method.querySelector('input[value="1"]').checked = true;
                removeAudio.checked = true;
            }
            removeAudio.disabled = createLoop.checked || !data.audio;
            // Original ersetzen nur, wenn das Format gleich bleibt
            var replace = mode.querySelector('input[value="replace"]');
            replace.disabled = isExact() && ['mp4', 'm4v', 'mov', 'webm'].indexOf(ext) < 0;
            if (replace.disabled && replace.checked) {
                mode.querySelector('input[value="version"]').checked = true;
            }
        };

        var startBtn = el('button', { type: 'button', class: 'btn btn-primary' }, icon('fa-cut'), ' ' + t.start);
        var posterBtn = el('button', { type: 'button', class: 'btn btn-default' }, icon('fa-camera'), ' ' + t.poster_save);
        var status = el('div', { class: 'ffmpeg-cut-status', 'aria-live': 'polite' });

        // ---- Hilfen
        var pct = function (s) {
            return (duration ? (s / duration) * 100 : 0) + '%';
        };
        var at = function (clientX) {
            var r = timeline.getBoundingClientRect();
            return Math.max(0, Math.min(duration, ((clientX - r.left) / r.width) * duration));
        };
        var seek = function (s) {
            video.currentTime = Math.max(0, Math.min(duration, s));
            draw();
        };
        var remember = function () {
            history.push(JSON.stringify(segs));
            if (history.length > 50) {
                history.shift();
            }
        };
        var keyBefore = function (s) {
            var k = 0;
            for (var i = 0; i < keyframes.length; i++) {
                if (keyframes[i] > s + 0.001) {
                    break;
                }
                k = keyframes[i];
            }
            return k;
        };
        // schnell: Abschnitte beginnen am Schlüsselbild davor (wie auf dem Server)
        var effective = function () {
            if (isExact() || !keyframes.length) {
                return segs.map(function (s) {
                    return s.slice();
                });
            }
            var out = [];
            segs.forEach(function (s) {
                var k = keyBefore(s[0]);
                var last = out[out.length - 1];
                if (last && k <= last[1]) {
                    last[1] = Math.max(last[1], s[1]);
                } else {
                    out.push([k, s[1]]);
                }
            });
            return out;
        };
        var kept = function () {
            return effective().reduce(function (sum, s) {
                return sum + (s[1] - s[0]);
            }, 0);
        };
        var indexAt = function (s) {
            return segs.findIndex(function (x) {
                return s >= x[0] && s < x[1];
            });
        };

        var draw = function () {
            var cur = video.currentTime || 0;
            head.style.left = pct(cur);
            now.textContent = clock(cur) + ' / ' + clock(duration);
            timeline.setAttribute('aria-valuenow', String(Math.round(cur * 10) / 10));
            timeline.setAttribute('aria-valuetext', clock(cur));
        };

        var render = function () {
            var nodes = [];
            var last = 0;
            segs.concat([[duration, duration]]).forEach(function (s) {
                if (s[0] - last > 0.001) {
                    var gap = el('div', { class: 'ffmpeg-cut-gap', 'aria-hidden': 'true' });
                    gap.style.left = pct(last);
                    gap.style.width = pct(s[0] - last);
                    nodes.push(gap);
                }
                last = s[1];
            });
            var lead = 0;
            if (!isExact() && keyframes.length) {
                segs.forEach(function (s, i) {
                    var from = Math.max(keyBefore(s[0]), i > 0 ? segs[i - 1][1] : 0);
                    if (s[0] - from > 0.001) {
                        lead = Math.max(lead, s[0] - from);
                        var extra = el('div', { class: 'ffmpeg-cut-lead', 'aria-hidden': 'true' });
                        extra.style.left = pct(from);
                        extra.style.width = pct(s[0] - from);
                        nodes.push(extra);
                    }
                });
            }
            var eff = effective();
            var whole = eff.length === 1 && eff[0][0] < MIN && eff[0][1] > duration - MIN;
            // ganzes Video nur, wenn trotzdem etwas passiert (Ton entfernen, Ping-Pong)
            var nothing = whole && !removeAudio.checked && !createLoop.checked;
            ticks.hidden = isExact();
            snapNote.textContent = whole && !isExact() && segs.length > 1 ? t.snap_none : (lead > 0.3 ? fill(t.snap_note, clock(lead)) : '');
            snapNote.hidden = snapNote.textContent === '';
            segs.forEach(function (s, i) {
                var label = fill(t.segment, i + 1, clock(s[0]), clock(s[1]));
                var seg = el('button', { type: 'button', class: 'ffmpeg-cut-seg', 'data-seg': String(i), 'aria-pressed': String(i === sel), 'aria-label': label, title: label });
                seg.style.left = pct(s[0]);
                seg.style.width = pct(s[1] - s[0]);
                if (i === sel) {
                    seg.append(el('span', { class: 'ffmpeg-cut-handle', 'data-edge': '0', 'aria-hidden': 'true' }),
                        el('span', { class: 'ffmpeg-cut-handle', 'data-edge': '1', 'aria-hidden': 'true' }));
                }
                nodes.push(seg);
            });
            layer.replaceChildren.apply(layer, nodes);
            selTitle.textContent = fill(t.segment, sel + 1, clock(segs[sel][0]), clock(segs[sel][1]));
            if (document.activeElement !== startInput) {
                startInput.value = segs[sel][0].toFixed(1);
            }
            if (document.activeElement !== endInput) {
                endInput.value = segs[sel][1].toFixed(1);
            }
            lengthInfo.textContent = fill(t.length, clock(kept() * (createLoop.checked ? 2 : 1)), clock(duration));
            removeBtn.disabled = segs.length < 2;
            undoBtn.disabled = history.length === 0;
            startBtn.disabled = nothing || root.dataset.busy === '1';
            draw();
        };

        var stopPingPong = function () {
            if (pingPong) {
                clearInterval(pingPong);
                pingPong = null;
            }
        };
        // Die Vorschau („Ergebnis abspielen“, Schleife, Ping-Pong) spielt immer genau die Markierung – auch beim
        // schnellen Schnitt, der ab dem Schlüsselbild davor beginnt (das zeigt der gelbe Bereich und der Hinweis).
        var marked = function () {
            return segs;
        };

        // Ping-Pong-Vorschau: rückwärts durch Zurückspringen in kleinen Schritten (Browser spielen nicht rückwärts)
        var reverse = function () {
            video.pause();
            var eff = marked();
            var from = eff[0][0];
            var step = 1 / 25;
            pingPong = setInterval(function () {
                var next = video.currentTime - step;
                // entfernte Teile überspringen
                var inSeg = eff.some(function (s) {
                    return next >= s[0] && next < s[1];
                });
                if (!inSeg) {
                    var prev = eff.filter(function (s) {
                        return s[1] <= next + step;
                    }).pop();
                    next = prev ? prev[1] - step : from;
                }
                if (next <= from) {
                    video.currentTime = from;
                    stopPingPong();
                    if (loopPreview.checked) {
                        previewing = 'pingpong';
                        video.play();
                    } else {
                        previewing = false;
                    }
                    draw();
                    return;
                }
                video.currentTime = next;
                draw();
            }, 40);
        };

        var ops = {
            split: function () {
                var cur = video.currentTime;
                var i = indexAt(cur);
                if (i < 0 || cur - segs[i][0] < MIN || segs[i][1] - cur < MIN) {
                    return;
                }
                remember();
                segs.splice(i, 1, [segs[i][0], cur], [cur, segs[i][1]]);
                sel = i + 1;
            },
            in: function () {
                var b = segs[sel][1];
                var prev = sel > 0 ? segs[sel - 1][1] : 0;
                remember();
                segs[sel] = [Math.max(prev, Math.min(video.currentTime, b - MIN)), b];
            },
            out: function () {
                var a = segs[sel][0];
                var next = sel < segs.length - 1 ? segs[sel + 1][0] : duration;
                remember();
                segs[sel] = [a, Math.min(next, Math.max(video.currentTime, a + MIN))];
            },
            remove: function () {
                if (segs.length < 2) {
                    return;
                }
                remember();
                segs.splice(sel, 1);
                sel = Math.min(sel, segs.length - 1);
            },
            undo: function () {
                if (!history.length) {
                    return;
                }
                segs = JSON.parse(history.pop());
                sel = Math.min(sel, segs.length - 1);
            },
            reset: function () {
                remember();
                segs = [[0, duration]];
                sel = 0;
            },
            toggle: function () {
                stopPingPong();
                previewing = false;
                if (video.paused) {
                    video.play();
                } else {
                    video.pause();
                }
            },
            result: function () {
                stopPingPong();
                if (previewing && !video.paused) {
                    video.pause();
                    return;
                }
                previewing = 'result';
                video.currentTime = marked()[0][0];
                video.play();
            },
            pingpong: function () {
                stopPingPong();
                previewing = 'pingpong';
                video.currentTime = marked()[0][0];
                video.play();
            },
        };
        var run = function (name) {
            if (ops[name]) {
                ops[name]();
            }
            render();
        };

        // Wiedergabe: Abspielkopf mitführen; bei „Ergebnis“/„Ping-Pong“ Lücken überspringen
        var raf = 0;
        var tick = function () {
            if (previewing) {
                var eff = marked();
                var cur = video.currentTime;
                var i = eff.findIndex(function (s) {
                    return cur >= s[0] && cur < s[1];
                });
                var end = eff[eff.length - 1][1];
                var atEnd = cur >= end - 0.03;
                if (!atEnd && (i < 0 || cur >= eff[i][1] - 0.03)) {
                    var nextSeg = eff.find(function (s) {
                        return s[0] > cur;
                    });
                    if (nextSeg) {
                        video.currentTime = nextSeg[0];
                    }
                } else if (atEnd) {
                    if (previewing === 'pingpong') {
                        reverse();
                    } else if (loopPreview.checked) {
                        video.currentTime = eff[0][0];
                    } else {
                        video.pause();
                        previewing = false;
                    }
                }
            }
            draw();
            if (!video.paused) {
                raf = requestAnimationFrame(tick);
            }
        };
        video.addEventListener('play', function () {
            playBtn.replaceChildren(icon('fa-pause'));
            resultBtn.replaceChildren(icon(previewing === 'result' ? 'fa-stop' : 'fa-forward'), ' ' + (previewing === 'result' ? t.stop : t.play_result));
            cancelAnimationFrame(raf);
            raf = requestAnimationFrame(tick);
        });
        video.addEventListener('pause', function () {
            playBtn.replaceChildren(icon('fa-play'));
            resultBtn.replaceChildren(icon('fa-forward'), ' ' + t.play_result);
            if (!pingPong) {
                previewing = false;
            }
            draw();
        });
        // ganzes Video in Schleife (Schleifen-Vorschau ohne Bereich)
        video.addEventListener('ended', function () {
            if (loopPreview.checked && !previewing) {
                video.currentTime = 0;
                video.play();
            }
        });
        video.addEventListener('seeked', draw);
        video.addEventListener('click', function () {
            run('toggle');
        });

        // Zeiger: Zeitleiste scrubben, Abschnitt wählen, Ränder ziehen
        timeline.addEventListener('pointerdown', function (e) {
            var handle = e.target.closest('.ffmpeg-cut-handle');
            var seg = e.target.closest('[data-seg]');
            e.preventDefault();
            timeline.focus({ preventScroll: true });
            stopPingPong();
            video.pause();
            timeline.setPointerCapture(e.pointerId);
            var move;
            if (handle) {
                var edge = Number(handle.dataset.edge);
                remember();
                move = function (x) {
                    var v = at(x);
                    var a = segs[sel][0];
                    var b = segs[sel][1];
                    if (edge === 0) {
                        var prev = sel > 0 ? segs[sel - 1][1] : 0;
                        segs[sel] = [Math.max(prev, Math.min(v, b - MIN)), b];
                        video.currentTime = segs[sel][0];
                    } else {
                        var next = sel < segs.length - 1 ? segs[sel + 1][0] : duration;
                        segs[sel] = [a, Math.min(next, Math.max(v, a + MIN))];
                        video.currentTime = segs[sel][1];
                    }
                    render();
                };
            } else {
                if (seg) {
                    sel = Number(seg.dataset.seg);
                    render();
                }
                move = function (x) {
                    seek(at(x));
                };
                move(e.clientX);
            }
            var onMove = function (ev) {
                move(ev.clientX);
            };
            var onUp = function () {
                timeline.removeEventListener('pointermove', onMove);
                timeline.removeEventListener('pointerup', onUp);
                timeline.removeEventListener('pointercancel', onUp);
            };
            timeline.addEventListener('pointermove', onMove);
            timeline.addEventListener('pointerup', onUp);
            timeline.addEventListener('pointercancel', onUp);
        });
        // Tastatur auf dem Abschnitt: Enter/Leertaste wählt ihn
        layer.addEventListener('click', function (e) {
            var seg = e.target.closest('[data-seg]');
            if (seg && e.detail === 0) {
                sel = Number(seg.dataset.seg);
                seek(segs[sel][0]);
                render();
                var focus = layer.querySelector('[data-seg="' + sel + '"]');
                if (focus) {
                    focus.focus();
                }
            }
        });
        controls.addEventListener('click', function (e) {
            var b = e.target.closest('button');
            if (!b) {
                return;
            }
            if (b.dataset.step) {
                stopPingPong();
                video.pause();
                seek(video.currentTime + Number(b.dataset.step));
            } else if (b.dataset.cut) {
                run(b.dataset.cut);
            }
        });
        // genaue Zeiten eintippen
        var applyInputs = function () {
            var a = parseFloat(startInput.value);
            var b = parseFloat(endInput.value);
            if (isNaN(a) || isNaN(b)) {
                return;
            }
            var prev = sel > 0 ? segs[sel - 1][1] : 0;
            var next = sel < segs.length - 1 ? segs[sel + 1][0] : duration;
            a = Math.max(prev, Math.min(a, next - MIN));
            b = Math.min(next, Math.max(b, a + MIN));
            remember();
            segs[sel] = [a, b];
            render();
        };
        startInput.addEventListener('change', applyInputs);
        endInput.addEventListener('change', applyInputs);
        [method, extras, mode].forEach(function (box) {
            box.addEventListener('change', function () {
                syncOptions();
                render();
            });
        });

        root.addEventListener('keydown', function (e) {
            var field = e.target.closest('input, textarea, select');
            var onButton = e.target.closest('button') && !e.target.closest('.ffmpeg-cut-seg');
            var key = e.key.toLowerCase();
            if (e.metaKey || e.ctrlKey) {
                // Strg+S / Strg+E wie beim bisherigen Trimmer: Anfang / Ende hierher; Strg+Z rückgängig
                var ctrl = { s: 'in', e: 'out', z: 'undo' }[key];
                if (ctrl && !field) {
                    e.preventDefault();
                    run(ctrl);
                }
                return;
            }
            if (field || e.altKey) {
                return;
            }
            var map = { s: 'split', i: 'in', o: 'out', delete: 'remove', backspace: 'remove' };
            if (map[key]) {
                e.preventDefault();
                run(map[key]);
            } else if (key === ' ' && !onButton) {
                e.preventDefault();
                run('toggle');
            } else if ((key === 'arrowleft' || key === 'arrowright') && !onButton) {
                e.preventDefault();
                video.pause();
                seek(video.currentTime + (key === 'arrowleft' ? -1 : 1) * (e.shiftKey ? 1 : frame));
            } else if ((key === 'home' || key === 'end') && !onButton) {
                e.preventDefault();
                seek(key === 'home' ? 0 : duration);
            }
        });

        // ---- Auftrag starten und verfolgen
        var setBusy = function (busy) {
            root.dataset.busy = busy ? '1' : '';
            render();
        };
        var follow = function (jobId) {
            var bar = el('div', { class: 'progress-bar', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', style: 'width:0%' });
            status.replaceChildren(el('p', { text: t.running }), el('div', { class: 'progress' }, bar));
            var poll = function () {
                api(cfg.file, 'job', { job: jobId }).then(function (json) {
                    var job = json.job || {};
                    if (job.status === 'done') {
                        var file = (job.result && job.result.filename) || cfg.file;
                        var box = alertBox('success', fill(t.done, file) + ' ');
                        if (cfg.mediaUrl) {
                            box.append(el('a', { href: cfg.mediaUrl + encodeURIComponent(file), text: t.open }));
                        }
                        status.replaceChildren(box);
                        setBusy(false);
                        return;
                    }
                    if (job.status === 'error' || job.error) {
                        status.replaceChildren(alertBox('danger', job.message || job.error || t.error));
                        setBusy(false);
                        return;
                    }
                    var p = job.status === 'finalizing' ? 99 : (parseInt(job.progress, 10) || 0);
                    bar.style.width = p + '%';
                    bar.setAttribute('aria-valuenow', String(p));
                    bar.textContent = p + ' %';
                    setTimeout(poll, 1500);
                }).catch(function (e) {
                    status.replaceChildren(alertBox('danger', e.message));
                    setBusy(false);
                });
            };
            poll();
        };
        startBtn.addEventListener('click', function () {
            setBusy(true);
            status.replaceChildren(el('p', { class: 'text-muted', text: t.starting }));
            api(cfg.file, 'cut', {
                segments: JSON.stringify(segs.map(function (s) {
                    return [Math.round(s[0] * 1000) / 1000, Math.round(s[1] * 1000) / 1000];
                })),
                exact: isExact() ? '1' : '0',
                remove_audio: removeAudio.checked ? '1' : '0',
                loop: createLoop.checked ? '1' : '0',
                mode: mode.querySelector('input:checked').value,
            }).then(function (json) {
                follow(json.job);
            }).catch(function (e) {
                status.replaceChildren(alertBox('danger', e.message));
                setBusy(false);
            });
        });
        posterBtn.addEventListener('click', function () {
            posterBtn.disabled = true;
            api(cfg.file, 'poster', { time: String(video.currentTime) }).then(function (json) {
                var box = alertBox('success', fill(t.poster_done, json.poster) + ' ');
                if (cfg.mediaUrl) {
                    box.append(el('a', { href: cfg.mediaUrl + encodeURIComponent(json.poster), text: t.open }));
                }
                status.replaceChildren(box);
            }).catch(function (e) {
                status.replaceChildren(alertBox('danger', e.message));
            }).then(function () {
                posterBtn.disabled = false;
            });
        });

        root.replaceChildren(
            el('div', { class: 'ffmpeg-cut-stage' }, video),
            controls,
            timeline,
            el('div', { class: 'ffmpeg-cut-info' }, selBox, lengthInfo),
            snapNote,
            el('p', { class: 'help-block ffmpeg-cut-help', text: t.help + ' ' + t.keys }),
            el('div', { class: 'ffmpeg-cut-options' }, method, extras, mode),
            el('div', { class: 'ffmpeg-cut-actions' }, startBtn, ' ', posterBtn,
                cfg.backUrl ? el('a', { class: 'btn btn-default', href: cfg.backUrl }, icon('fa-arrow-left'), ' ' + t.back) : null),
            el('p', { class: 'help-block', text: t.poster_help }),
            status
        );
        syncOptions();
        render();

        // Vorschaubilder und Schlüsselbilder der Zeitleiste nachladen
        api(cfg.file, 'frames').then(function (json) {
            strip.replaceChildren.apply(strip, (json.frames || []).map(function (src) {
                return el('img', { src: src, alt: '', loading: 'lazy', decoding: 'async' });
            }));
            keyframes = json.keyframes || [];
            ticks.replaceChildren.apply(ticks, keyframes.map(function (k) {
                var mark = el('span');
                mark.style.left = pct(k);
                return mark;
            }));
            render();
        }).catch(function () {});
    };

    // ============================================================================================ Untertitel

    // Zeit in ms ↔ Text „1:05.250“; liest auch „65.25“, „00:01:05,250“
    var stamp = function (ms) {
        ms = Math.max(0, Math.round(ms));
        return time(Math.floor(ms / 1000)) + '.' + String(ms % 1000).padStart(3, '0');
    };
    var readStamp = function (text) {
        var m = String(text).trim().replace(',', '.').match(/^(?:(\d+):)?(?:(\d+):)?(\d+(?:\.\d*)?)$/);
        if (!m) {
            return null;
        }
        var parts = [m[1], m[2]].filter(function (x) {
            return x !== undefined;
        }).map(Number);
        var sec = Number(m[3]);
        var h = parts.length === 2 ? parts[0] : 0;
        var min = parts.length === 2 ? parts[1] : (parts[0] || 0);
        return Math.round(((h * 60 + min) * 60 + sec) * 1000);
    };

    var initTracks = function (root) {
        if (root.dataset.ffmpegReady) {
            return;
        }
        root.dataset.ffmpegReady = '1';
        var cfg = JSON.parse(root.dataset.ffmpegTracks);
        var t = cfg.i18n;
        var data = null;

        var renderList = function () {
            var list = data.tracks.length
                ? el('ul', { class: 'list-group ffmpeg-track-list' }, data.tracks.map(function (tr) {
                    return el('li', { class: 'list-group-item' },
                        el('span', {}, el('strong', { text: tr.name }),
                            el('span', { class: 'text-muted', text: ' · ' + (t['role_' + tr.role] || tr.role) + ' · ' + fill(t.cues, tr.cues) + ' · ' + tr.file })),
                        ' ',
                        el('span', { class: 'pull-right' },
                            el('a', { class: 'btn btn-default btn-xs', href: tr.url, target: '_blank', rel: 'noopener', text: '.vtt' }), ' ',
                            el('button', { type: 'button', class: 'btn btn-primary btn-xs', 'data-track': tr.file }, icon('fa-pencil'), ' ' + t.edit))
                    );
                }))
                : el('p', { class: 'text-muted', text: t.none });
            var preview = el('video', { class: 'ffmpeg-track-preview', src: data.stream, controls: true, preload: 'metadata', playsinline: true });
            data.tracks.forEach(function (tr) {
                preview.append(el('track', { kind: tr.role, src: tr.url, srclang: tr.lang, label: tr.name }));
            });
            root.replaceChildren(
                preview,
                el('h4', { text: t.tracks }),
                list,
                el('p', {}, el('button', { type: 'button', class: 'btn btn-primary', 'data-track': '' }, icon('fa-plus'), ' ' + t.add)),
                el('p', { class: 'help-block', text: t.intro })
            );
        };

        var openEditor = function (file) {
            var existing = data.tracks.find(function (tr) {
                return tr.file === file;
            }) || null;
            var load = existing ? api(cfg.file, 'track', { track: existing.file }).then(function (json) {
                return json.cues;
            }) : Promise.resolve([]);
            load.then(function (cues) {
                buildEditor(existing, cues);
            }).catch(function (e) {
                root.prepend(alertBox('danger', e.message));
            });
        };

        var buildEditor = function (existing, cues) {
            var duration = (data.duration || 0) * 1000;
            var frame = 1 / (data.fps || 25);
            var video = el('video', { class: 'ffmpeg-track-video', src: data.stream, preload: 'auto', playsinline: true, controls: true });
            var textTrack = video.addTextTrack('subtitles', t.title, (existing && existing.lang) || data.lang || 'de');
            textTrack.mode = 'showing';
            var preview = function () {
                Array.prototype.slice.call(textTrack.cues || []).forEach(function (c) {
                    textTrack.removeCue(c);
                });
                cues.forEach(function (c) {
                    if (c.end > c.start && c.text.trim()) {
                        textTrack.addCue(new VTTCue(c.start / 1000, c.end / 1000, c.text));
                    }
                });
            };

            var now = el('output', { class: 'ffmpeg-cut-now', text: stamp(0) });
            var controls = el('div', { class: 'ffmpeg-cut-group' },
                btn('−1 s', null, { 'data-step': '-1' }), btn('−1 ' + t.frame, null, { 'data-step': String(-frame) }), now,
                btn('+1 ' + t.frame, null, { 'data-step': String(frame) }), btn('+1 s', null, { 'data-step': '1' }),
                btn(t.new_cue, 'fa-plus', { 'data-cue-new': '', 'aria-keyshortcuts': 'N', class: 'btn btn-primary btn-sm' })
            );

            var uid = 'ffmpeg-track-' + Math.random().toString(36).slice(2, 8);
            var langInput = el('input', { class: 'form-control', id: uid + '-lang', value: (existing && existing.lang) || data.lang || 'de', maxlength: '20', autocomplete: 'off', spellcheck: 'false' });
            var roleSelect = el('select', { class: 'form-control', id: uid + '-role' }, ['subtitles', 'captions', 'chapters'].map(function (r) {
                return el('option', { value: r, selected: ((existing && existing.role) || 'subtitles') === r }, t['role_' + r]);
            }));
            var labelInput = el('input', { class: 'form-control', id: uid + '-label', value: (existing && existing.label) || '', maxlength: '60', autocomplete: 'off' });
            var meta = el('div', { class: 'ffmpeg-track-meta' },
                el('div', { class: 'form-group' }, el('label', { for: uid + '-lang', text: t.lang }), langInput),
                el('div', { class: 'form-group' }, el('label', { for: uid + '-role', text: t.role }), roleSelect),
                el('div', { class: 'form-group ffmpeg-track-meta-wide' }, el('label', { for: uid + '-label', text: t.label }), labelInput,
                    el('small', { class: 'help-block', text: t.label_help }))
            );

            // .hidden statt [hidden]: Bootstrap zeigt Dateifelder sonst trotzdem an
            var fileInput = el('input', { type: 'file', accept: '.vtt,.srt,text/vtt', class: 'hidden', 'aria-hidden': 'true', tabindex: '-1' });
            var shiftInput = el('input', { class: 'form-control input-sm', type: 'number', step: '0.1', value: '0', id: uid + '-shift' });
            var tools = el('div', { class: 'ffmpeg-track-tools' },
                btn(t.import, 'fa-upload', { 'data-cue-import': '' }), fileInput,
                el('label', { for: uid + '-shift', class: 'ffmpeg-track-shift' }, t.shift, shiftInput),
                btn(t.shift_apply, null, { 'data-cue-shift': '' })
            );
            var list = el('ol', { class: 'ffmpeg-cue-list', 'aria-label': t.list });
            var count = el('p', { class: 'text-muted ffmpeg-track-count', 'aria-live': 'polite' });
            var message = el('div', {});

            var row = function (c, i) {
                var startIn = el('input', { class: 'form-control input-sm ffmpeg-cue-time', value: stamp(c.start), 'aria-label': t.from + ' (' + (i + 1) + ')', 'data-cue-field': 'start', spellcheck: 'false' });
                var endIn = el('input', { class: 'form-control input-sm ffmpeg-cue-time', value: stamp(c.end), 'aria-label': t.to + ' (' + (i + 1) + ')', 'data-cue-field': 'end', spellcheck: 'false' });
                var text = el('textarea', { class: 'form-control ffmpeg-cue-text', rows: '2', 'aria-label': t.text + ' (' + (i + 1) + ')', 'data-cue-field': 'text' });
                text.value = c.text;
                var small = function (label, name, attrs) {
                    return btn('', name, Object.assign({ class: 'btn btn-default btn-xs', title: label, 'aria-label': label }, attrs));
                };
                return el('li', { class: 'ffmpeg-cue', 'data-cue': String(i) },
                    el('div', { class: 'ffmpeg-cue-head' },
                        el('span', { class: 'ffmpeg-cue-no', text: String(i + 1) }),
                        startIn, small(t.set_start, 'fa-sign-in', { 'data-cue-set': 'start' }),
                        el('span', { 'aria-hidden': 'true', text: '–' }),
                        endIn, small(t.set_end, 'fa-sign-out', { 'data-cue-set': 'end' }),
                        small(t.jump, 'fa-play', { 'data-cue-jump': '' }),
                        small(t.delete_cue, 'fa-trash', { 'data-cue-delete': '' })
                    ),
                    text
                );
            };
            var highlight = function () {
                var cur = video.currentTime * 1000;
                now.textContent = stamp(cur);
                list.querySelectorAll('.ffmpeg-cue').forEach(function (li) {
                    var c = cues[Number(li.dataset.cue)];
                    var on = c && cur >= c.start && cur < c.end;
                    if (on !== li.classList.contains('is-active')) {
                        li.classList.toggle('is-active', on);
                        if (on && !video.paused && !list.contains(document.activeElement)) {
                            li.scrollIntoView({ block: 'nearest' });
                        }
                    }
                });
            };
            var renderCues = function (focusIndex) {
                cues.sort(function (a, b) {
                    return a.start - b.start || a.end - b.end;
                });
                list.replaceChildren.apply(list, cues.map(row));
                count.textContent = fill(t.cues, cues.length);
                preview();
                highlight();
                if (focusIndex !== undefined && focusIndex !== null) {
                    var li = list.querySelector('[data-cue="' + focusIndex + '"]');
                    if (li) {
                        li.querySelector('textarea').focus();
                        li.scrollIntoView({ block: 'nearest' });
                    }
                }
            };
            video.addEventListener('timeupdate', highlight);
            video.addEventListener('seeked', highlight);

            var newCue = function () {
                var cur = Math.round(video.currentTime * 1000);
                var next = cues.filter(function (c) {
                    return c.start > cur;
                }).sort(function (a, b) {
                    return a.start - b.start;
                })[0];
                var end = Math.min(cur + 2000, next ? next.start : Infinity, duration || Infinity);
                var cue = { start: cur, end: end > cur ? end : cur + 2000, text: '', settings: '' };
                cues.push(cue);
                video.pause();
                cues.sort(function (a, b) {
                    return a.start - b.start;
                });
                renderCues(cues.indexOf(cue));
            };

            list.addEventListener('input', function (e) {
                var li = e.target.closest('[data-cue]');
                var c = li && cues[Number(li.dataset.cue)];
                if (!c) {
                    return;
                }
                var field = e.target.dataset.cueField;
                if (field === 'text') {
                    c.text = e.target.value;
                } else {
                    var ms = readStamp(e.target.value);
                    e.target.setCustomValidity(ms === null ? t.time_invalid : '');
                    if (ms !== null) {
                        c[field] = ms;
                    }
                }
                preview();
            });
            list.addEventListener('change', function (e) {
                var field = e.target.dataset.cueField;
                if (field === 'start' || field === 'end') {
                    var c = cues[Number(e.target.closest('[data-cue]').dataset.cue)];
                    if (c) {
                        e.target.value = stamp(c[field]);
                    }
                }
            });
            list.addEventListener('click', function (e) {
                var b = e.target.closest('button');
                var li = e.target.closest('[data-cue]');
                if (!b || !li) {
                    return;
                }
                var i = Number(li.dataset.cue);
                var c = cues[i];
                if (b.hasAttribute('data-cue-delete')) {
                    cues.splice(i, 1);
                    renderCues();
                } else if (b.hasAttribute('data-cue-jump')) {
                    video.currentTime = c.start / 1000;
                    video.play();
                } else if (b.dataset.cueSet) {
                    c[b.dataset.cueSet] = Math.round(video.currentTime * 1000);
                    if (c.end <= c.start) {
                        c.end = c.start + 1000;
                    }
                    li.querySelector('[data-cue-field="start"]').value = stamp(c.start);
                    li.querySelector('[data-cue-field="end"]').value = stamp(c.end);
                    preview();
                }
            });
            controls.addEventListener('click', function (e) {
                var b = e.target.closest('button');
                if (!b) {
                    return;
                }
                if (b.dataset.step) {
                    video.pause();
                    video.currentTime = Math.max(0, video.currentTime + Number(b.dataset.step));
                } else if (b.hasAttribute('data-cue-new')) {
                    newCue();
                }
            });
            tools.addEventListener('click', function (e) {
                var b = e.target.closest('button');
                if (!b) {
                    return;
                }
                if (b.hasAttribute('data-cue-import')) {
                    fileInput.click();
                } else if (b.hasAttribute('data-cue-shift')) {
                    var delta = Math.round(Number(shiftInput.value || 0) * 1000);
                    if (!delta) {
                        return;
                    }
                    cues.forEach(function (c) {
                        c.start = Math.max(0, c.start + delta);
                        c.end = Math.max(c.start + 100, c.end + delta);
                    });
                    shiftInput.value = '0';
                    renderCues();
                }
            });
            fileInput.addEventListener('change', function () {
                var f = fileInput.files && fileInput.files[0];
                if (!f) {
                    return;
                }
                f.text().then(function (content) {
                    return api(cfg.file, 'track_parse', { content: content });
                }).then(function (json) {
                    cues = json.cues;
                    renderCues();
                }).catch(function (err) {
                    message.replaceChildren(alertBox('danger', err.message));
                });
                fileInput.value = '';
            });

            var save = el('button', { type: 'button', class: 'btn btn-primary' }, icon('fa-check'), ' ' + t.save);
            var cancel = el('button', { type: 'button', class: 'btn btn-default' }, t.cancel);
            var remove = existing ? el('button', { type: 'button', class: 'btn btn-danger pull-right' }, icon('fa-trash'), ' ' + t.remove) : null;

            var editor = el('div', { class: 'ffmpeg-track-editor' },
                el('h4', { text: existing ? t.title + ': ' + existing.name : t.add }),
                message,
                el('div', { class: 'ffmpeg-track-layout' },
                    el('div', { class: 'ffmpeg-track-main' }, video, controls, meta),
                    el('div', { class: 'ffmpeg-track-side' }, tools, count, list)
                ),
                el('p', { class: 'help-block', text: t.keys }),
                el('div', { class: 'ffmpeg-track-actions' }, save, ' ', cancel, remove)
            );
            editor.addEventListener('keydown', function (e) {
                if (e.target.closest('input, textarea, select') || e.metaKey || e.ctrlKey || e.altKey) {
                    return;
                }
                var key = e.key.toLowerCase();
                if (key === 'n') {
                    e.preventDefault();
                    newCue();
                } else if (key === ' ' && !e.target.closest('button')) {
                    e.preventDefault();
                    if (video.paused) {
                        video.play();
                    } else {
                        video.pause();
                    }
                } else if ((key === 'arrowleft' || key === 'arrowright') && !e.target.closest('button')) {
                    e.preventDefault();
                    video.pause();
                    video.currentTime = Math.max(0, video.currentTime + (key === 'arrowleft' ? -1 : 1) * (e.shiftKey ? 1 : frame));
                }
            });
            cancel.addEventListener('click', function () {
                video.pause();
                renderList();
            });
            if (remove) {
                remove.addEventListener('click', function () {
                    // zweiter Klick bestätigt
                    if (!remove.dataset.confirm) {
                        remove.dataset.confirm = '1';
                        remove.replaceChildren(icon('fa-trash'), ' ' + t.remove_confirm);
                        return;
                    }
                    api(cfg.file, 'track_delete', { track: existing.file }).then(function (json) {
                        data.tracks = json.tracks;
                        renderList();
                    }).catch(function (err) {
                        message.replaceChildren(alertBox('danger', err.message));
                    });
                });
            }
            save.addEventListener('click', function () {
                save.disabled = true;
                api(cfg.file, 'track_save', {
                    track: existing ? existing.file : '',
                    lang: langInput.value,
                    role: roleSelect.value,
                    label: labelInput.value,
                    cues: JSON.stringify(cues),
                }).then(function (json) {
                    data.tracks = json.tracks;
                    renderList();
                    root.prepend(alertBox('success', fill(t.saved, json.track)));
                }).catch(function (err) {
                    save.disabled = false;
                    message.replaceChildren(alertBox('danger', err.message));
                });
            });

            root.replaceChildren(editor);
            renderCues();
            if (!cues.length) {
                newCue();
            }
        };

        root.addEventListener('click', function (e) {
            var b = e.target.closest('[data-track]');
            if (b && !e.target.closest('.ffmpeg-track-editor')) {
                openEditor(b.dataset.track || null);
            }
        });

        root.replaceChildren(el('p', { class: 'text-muted' }, icon('fa-spinner fa-spin'), ' …'));
        api(cfg.file, 'info').then(function (json) {
            data = json;
            renderList();
        }).catch(function (e) {
            root.replaceChildren(alertBox('danger', e.message));
        });
    };

    var scan = function (scope) {
        (scope || document).querySelectorAll('[data-ffmpeg-cut]').forEach(initCut);
        (scope || document).querySelectorAll('[data-ffmpeg-tracks]').forEach(initTracks);
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            scan();
        });
    } else {
        scan();
    }
    if (window.jQuery) {
        window.jQuery(document).on('rex:ready', function (e, container) {
            scan(container && container[0] ? container[0] : document);
        });
    }
})();
