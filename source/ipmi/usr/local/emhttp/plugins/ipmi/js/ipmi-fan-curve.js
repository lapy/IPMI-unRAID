/**
 * Multi-point fan curve editor (SVG + hidden wire + legacy INI sync).
 * Depends on jQuery.
 */
(function ($) {
    'use strict';

    var MAX_POINTS = 12;
    var TEMP_MIN_C = 0;
    var TEMP_MAX_C = 100;
    var SVG_W = 420;
    var SVG_H = 184;
    var PAD_L = 40;
    var PAD_R = 16;
    var PAD_T = 14;
    var PAD_B = 26;
    var PLOT_W = SVG_W - PAD_L - PAD_R;
    var PLOT_H = SVG_H - PAD_T - PAD_B;

    function parseWire(str) {
        str = (str || '').trim();
        if (!str) return [];
        var pts = [];
        str.split('|').forEach(function (chunk) {
            chunk = chunk.trim();
            if (!chunk) return;
            var parts = chunk.split(':');
            if (parts.length !== 2) return;
            var t = parseInt(parts[0], 10);
            var p = parseInt(parts[1], 10);
            if (isNaN(t) || isNaN(p)) return;
            pts.push({ t: t, p: p });
        });
        return sortPoints(pts);
    }

    function sortPoints(pts) {
        pts.sort(function (a, b) { return a.t - b.t; });
        var out = [];
        var last = null;
        pts.forEach(function (pt) {
            if (last !== null && pt.t === last) return;
            out.push({ t: pt.t, p: pt.p });
            last = pt.t;
        });
        return out;
    }

    function wireEncode(pts) {
        return sortPoints(pts).map(function (pt) {
            return pt.t + ':' + pt.p;
        }).join('|');
    }

    function cToDisplay(tempC, unit) {
        if (unit === 'F')
            return ((tempC * 9) / 5) + 32;
        return tempC;
    }

    function displayToC(temp, unit) {
        if (unit === 'F')
            return ((temp - 32) * 5) / 9;
        return temp;
    }

    function tempInputBounds(unit) {
        if (unit === 'F')
            return { min: 32, max: 212 };
        return { min: TEMP_MIN_C, max: TEMP_MAX_C };
    }

    function svgNode(tag) {
        return $(document.createElementNS('http://www.w3.org/2000/svg', tag));
    }

    function uniqueSortedValues(values) {
        return values
            .slice()
            .sort(function (a, b) { return a - b; })
            .filter(function (value, idx, arr) { return idx === 0 || value !== arr[idx - 1]; });
    }

    function tempTickLabel(tempC) {
        return Math.round(tempC) + ' C';
    }

    function clampTempC(tempC) {
        return Math.round(Math.max(TEMP_MIN_C, Math.min(TEMP_MAX_C, tempC)));
    }

    function clampPwm(pwm, range) {
        return Math.max(1, Math.min(range, Math.round(pwm)));
    }

    function pwmForPct(range, pct) {
        pct = Math.max(0, Math.min(100, pct));
        return clampPwm((pct / 100) * range, range);
    }

    function normalizePoints(points, range) {
        var clamped = points.map(function (pt) {
            return {
                t: clampTempC(pt.t),
                p: clampPwm(pt.p, range)
            };
        });
        return sortPoints(clamped);
    }

    function setFieldValue($field, value, notify) {
        if (!$field || !$field.length)
            return false;
        var next = String(value);
        var prev = String($field.val());
        if (prev === next)
            return false;
        $field.val(next);
        if (notify)
            $field.trigger('input').trigger('change');
        return true;
    }

    function Editor(root) {
        this.$root = $(root);
        this.fan = this.$root.data('fan');
        this.kind = this.$root.data('curve-kind') || 'primary';
        this.range = parseInt(this.$root.data('range'), 10) || 64;
        this.displayUnit = (this.$root.data('display-unit') === 'F') ? 'F' : 'C';
        this.readingC = this.$root.data('reading-c');
        this.readingC = (this.readingC === '' || this.readingC === undefined) ? null : parseFloat(this.readingC);
        this.$wire = this.$root.find('.ipmi-fan-curve-wire');
        this.$legacy = this.$root.find('.ipmi-fan-curve-legacy');
        this.$plot = this.$root.find('.ipmi-fan-curve-plot');
        this.$svg = this.$root.find('.ipmi-fan-curve-svg');
        this.$inpT = this.$root.find('.ipmi-fan-curve-inp-t');
        this.$inpP = this.$root.find('.ipmi-fan-curve-inp-p');
        this.points = normalizePoints(parseWire(this.$wire.val()), this.range);
        if (this.points.length < 2)
            this.points = [{ t: 30, p: Math.max(1, Math.round(this.range * 0.25)) }, { t: 45, p: Math.max(1, Math.round(this.range * 0.85)) }];
        this.sel = Math.max(0, this.points.length - 1);
        this.dragIdx = null;
        this.dragDirty = false;
        var bounds = tempInputBounds(this.displayUnit);
        this.$inpT.attr({ min: bounds.min, max: bounds.max });
    }

    Editor.prototype.domain = function () {
        return { min: TEMP_MIN_C, max: TEMP_MAX_C };
    };

    Editor.prototype.tx = function (t) {
        var d = this.domain();
        var span = Math.max(1, d.max - d.min);
        return PAD_L + ((t - d.min) / span) * PLOT_W;
    };

    Editor.prototype.ty = function (pwm) {
        var pct = (pwm / this.range) * 100;
        pct = Math.max(0, Math.min(100, pct));
        return PAD_T + PLOT_H - (pct / 100) * PLOT_H;
    };

    Editor.prototype.markFormDirty = function () {
        var $form = this.$root.closest('form');
        if (!$form.length)
            return;
        $form.find('input[type="submit"], button[type="submit"]').prop('disabled', false);
    };

    Editor.prototype.commit = function (notify, forceDirty) {
        notify = !!notify;
        forceDirty = !!forceDirty;
        this.points = normalizePoints(this.points, this.range);
        var changed = false;
        changed = setFieldValue(this.$wire, wireEncode(this.points), notify) || changed;
        var first = this.points[0];
        var last = this.points[this.points.length - 1];
        changed = setFieldValue(this.$root.find('.ipmi-fan-curve-legacy-lo'), first.t, notify) || changed;
        changed = setFieldValue(this.$root.find('.ipmi-fan-curve-legacy-hi'), last.t, notify) || changed;
        changed = setFieldValue(this.$root.find('.ipmi-fan-curve-legacy-min'), first.p, notify) || changed;
        changed = setFieldValue(this.$root.find('.ipmi-fan-curve-legacy-max'), last.p, notify) || changed;
        if (notify && (changed || forceDirty))
            this.markFormDirty();
    };

    Editor.prototype.draw = function () {
        var self = this;
        var g = this.$plot.empty();
        var d = this.domain();
        var xAxisY = PAD_T + PLOT_H;
        var xAxisX2 = PAD_L + PLOT_W;
        var yTicks = [0, 25, 50, 75, 100];
        var xTicks = [0, 25, 50, 75, 100];

        yTicks.forEach(function (pct) {
            var y = PAD_T + PLOT_H - ((pct / 100) * PLOT_H);
            g.append(svgNode('line')
                .attr({ x1: PAD_L, y1: y, x2: xAxisX2, y2: y, 'class': 'ipmi-fan-curve-grid' }));
            g.append(svgNode('text')
                .attr({ x: PAD_L - 8, y: y + 4, 'text-anchor': 'end', 'class': 'ipmi-fan-curve-label' })
                .text(pct + '%'));
        });

        xTicks.forEach(function (tick, idx) {
            var x = self.tx(tick);
            g.append(svgNode('line')
                .attr({ x1: x, y1: PAD_T, x2: x, y2: xAxisY, 'class': 'ipmi-fan-curve-grid' }));
            g.append(svgNode('text')
                .attr({
                    x: x,
                    y: xAxisY + 22,
                    'text-anchor': (idx === 0) ? 'start' : ((idx === xTicks.length - 1) ? 'end' : 'middle'),
                    'class': 'ipmi-fan-curve-label'
                })
                .text(tempTickLabel(tick)));
        });

        g.append(svgNode('line')
            .attr({ x1: PAD_L, y1: xAxisY, x2: xAxisX2, y2: xAxisY, 'class': 'ipmi-fan-curve-axis' }));
        g.append(svgNode('line')
            .attr({ x1: PAD_L, y1: PAD_T, x2: PAD_L, y2: xAxisY, 'class': 'ipmi-fan-curve-axis' }));

        if (this.readingC !== null && !isNaN(this.readingC) && this.readingC >= d.min && this.readingC <= d.max) {
            var rx = this.tx(this.readingC);
            g.append(svgNode('line')
                .attr({ x1: rx, y1: PAD_T, x2: rx, y2: xAxisY, 'class': 'ipmi-fan-curve-reading' }));
        }

        var path = '';
        this.points.forEach(function (pt, i) {
            var x = self.tx(pt.t);
            var y = self.ty(pt.p);
            path += (i === 0 ? 'M' : 'L') + x.toFixed(1) + ' ' + y.toFixed(1) + ' ';
        });
        g.append(
            svgNode('path')
                .attr('d', path.trim())
                .attr('class', 'ipmi-fan-curve-line')
        );

        this.points.forEach(function (pt, i) {
            var x = self.tx(pt.t);
            var y = self.ty(pt.p);
            var c = svgNode('circle')
                .attr({ cx: x, cy: y, r: i === self.sel ? 5.5 : 4 })
                .attr('class', 'ipmi-fan-curve-point' + (i === self.sel ? ' is-selected' : ''))
                .attr('data-idx', i)
                .css('cursor', 'grab');
            g.append(c);
        });

        var pt = this.points[this.sel];
        this.$inpT.val(String(Math.round(cToDisplay(pt.t, this.displayUnit))));
        this.$inpP.val((pt.p / this.range * 100).toFixed(1));
    };

    Editor.prototype.setSelected = function (i) {
        this.sel = Math.max(0, Math.min(this.points.length - 1, i));
        this.draw();
    };

    Editor.prototype.applyPreset = function (name) {
        var r = this.range;
        var presets = {
            quiet: [
                { t: 0, pct: 25 },
                { t: 20, pct: 25 },
                { t: 35, pct: 27 },
                { t: 45, pct: 31 },
                { t: 55, pct: 38 },
                { t: 65, pct: 48 },
                { t: 75, pct: 62 },
                { t: 85, pct: 78 },
                { t: 100, pct: 100 }
            ],
            balanced: [
                { t: 0, pct: 28 },
                { t: 20, pct: 28 },
                { t: 35, pct: 32 },
                { t: 45, pct: 40 },
                { t: 55, pct: 50 },
                { t: 65, pct: 62 },
                { t: 75, pct: 75 },
                { t: 85, pct: 90 },
                { t: 100, pct: 100 }
            ],
            performance: [
                { t: 0, pct: 35 },
                { t: 15, pct: 36 },
                { t: 30, pct: 42 },
                { t: 40, pct: 52 },
                { t: 50, pct: 64 },
                { t: 60, pct: 76 },
                { t: 70, pct: 88 },
                { t: 80, pct: 98 },
                { t: 100, pct: 100 }
            ],
            flat: [
                { t: 0, pct: 25 },
                { t: 25, pct: 25 },
                { t: 50, pct: 25 },
                { t: 75, pct: 25 },
                { t: 100, pct: 25 }
            ]
        };
        if (presets[name])
            this.points = normalizePoints(presets[name].map(function (p) {
                return { t: p.t, p: pwmForPct(r, p.pct) };
            }), this.range);
        this.sel = 0;
        this.commit(true);
        this.draw();
    };

    Editor.prototype.addPoint = function () {
        if (this.points.length >= MAX_POINTS) return;
        var best = null;
        var bestGap = -1;
        for (var i = 0; i < this.points.length - 1; i++) {
            var gap = this.points[i + 1].t - this.points[i].t;
            if (gap > bestGap) {
                bestGap = gap;
                best = i;
            }
        }
        if (best === null) return;
        var a = this.points[best];
        var b = this.points[best + 1];
        var tm = Math.round((a.t + b.t) / 2);
        var pm = Math.round((a.p + b.p) / 2);
        tm = clampTempC(tm);
        pm = clampPwm(pm, this.range);
        this.points.splice(best + 1, 0, { t: tm, p: pm });
        this.sel = best + 1;
        this.commit(true);
        this.draw();
    };

    Editor.prototype.removePoint = function () {
        if (this.points.length <= 2) return;
        this.points.splice(this.sel, 1);
        this.sel = Math.min(this.sel, this.points.length - 1);
        this.commit(true);
        this.draw();
    };

    function svgClientPoint(svg, evt) {
        var pt = svg.createSVGPoint();
        pt.x = evt.clientX;
        pt.y = evt.clientY;
        var ctm = svg.getScreenCTM();
        if (!ctm) return { x: 0, y: 0 };
        return pt.matrixTransform(ctm.inverse());
    }

    Editor.prototype.bind = function () {
        var self = this;
        this.$root.on('click', '.ipmi-fan-curve-preset', function () {
            self.applyPreset($(this).data('preset'));
        });
        this.$root.on('click', '.ipmi-fan-curve-add', function () { self.addPoint(); });
        this.$root.on('click', '.ipmi-fan-curve-remove', function () { self.removePoint(); });

        this.$svg.on('mousedown', 'circle', function (e) {
            e.preventDefault();
            self.dragIdx = parseInt($(this).attr('data-idx'), 10);
            self.sel = self.dragIdx;
            self.draw();
        });

        this.$svg.on('mousemove', function (e) {
            if (self.dragIdx === null) return;
            var svg = self.$svg[0];
            var p = svgClientPoint(svg, e);
            var d = self.domain();
            var span = Math.max(1, d.max - d.min);
            var t = d.min + ((p.x - PAD_L) / PLOT_W) * span;
            var pwm = (1 - ((p.y - PAD_T) / PLOT_H)) * self.range;
            t = clampTempC(t);
            pwm = clampPwm(pwm, self.range);
            self.points[self.dragIdx].t = t;
            self.points[self.dragIdx].p = pwm;
            self.points = normalizePoints(self.points, self.range);
            for (var i = 0; i < self.points.length; i++) {
                if (self.points[i].t === t && self.points[i].p === pwm) {
                    self.sel = i;
                    self.dragIdx = i;
                    break;
                }
            }
            self.dragDirty = true;
            self.draw();
        });

        this.$svg.on('mouseup mouseleave', function () {
            if (self.dragIdx !== null && self.dragDirty) {
                self.commit(true, true);
                self.draw();
            }
            self.dragIdx = null;
            self.dragDirty = false;
        });

        this.$inpT.on('change', function () {
            var v = parseFloat(self.$inpT.val());
            if (isNaN(v)) return;
            var tempC = clampTempC(displayToC(v, self.displayUnit));
            self.points[self.sel].t = tempC;
            self.points = normalizePoints(self.points, self.range);
            self.commit(true);
            self.draw();
        });
        this.$inpP.on('change', function () {
            var pct = parseFloat(self.$inpP.val());
            if (isNaN(pct)) return;
            pct = Math.max(0, Math.min(100, pct));
            self.points[self.sel].p = clampPwm((pct / 100) * self.range, self.range);
            self.commit(true);
            self.draw();
        });
    };

    function initAll() {
        $('.ipmi-fan-curve-editor').each(function () {
            if ($(this).data('ipmiFanCurveEditor'))
                return;
            var ed = new Editor(this);
            $(this).data('ipmiFanCurveEditor', ed);
            ed.bind();
            ed.commit();
            ed.draw();
        });
    }

    function validateWire(wire, range, label) {
        var errs = [];
        var pts = parseWire(wire);
        if (pts.length < 2)
            return [label + ': add at least two curve points.'];
        if (pts.length > MAX_POINTS)
            errs.push(label + ': at most ' + MAX_POINTS + ' points.');
        var prev = null;
        for (var i = 0; i < pts.length; i++) {
            if (pts[i].t < TEMP_MIN_C || pts[i].t > TEMP_MAX_C)
                errs.push(label + ': temperatures must stay between ' + TEMP_MIN_C + ' C and ' + TEMP_MAX_C + ' C.');
            if (prev !== null && pts[i].t <= prev)
                errs.push(label + ': temperatures must increase along the curve.');
            prev = pts[i].t;
            if (pts[i].p < 1 || pts[i].p > range)
                errs.push(label + ': duty must be between 1 and ' + range + '.');
        }
        return errs;
    }

    window.IpmiFanCurve = {
        initAll: initAll,
        parseWire: parseWire,
        wireEncode: wireEncode,
        sortPoints: sortPoints,
        validateWire: validateWire
    };
})(jQuery);
