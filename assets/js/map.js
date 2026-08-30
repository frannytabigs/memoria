const MAP = (() => {
  // ── CONSTANTS ────────────────────────────────────────────
  const CELL = 22;
  const STORAGE_KEY = "cemetery-map-v1";
  const DEFAULT_SCHEME = "1A_asc";
  const LONG_PRESS_MS = 350;

  // ── STATE ────────────────────────────────────────────────
  let mapEditMode = false;
  let blockEditMode = false;
  let addMode = false;
  let resizeMode = false;
  let reshapeMode = false;
  let reshapeBid = null;
  let reshapeTool = "cut";
  let reshapeHistory = [];
  let cutFirstPoint = null;
  let drawPoints = [];
  let mousePos = { x: 0, y: 0 };
  let resizingBid = null;
  let view = "map";
  let curBlock = null;
  let curPlot = null;
  let curFloor = 1;
  let hoveredBlock = null;
  let pendingNavHref = null; // link the user tried to navigate to while editing
  let drag = { active: false, sx: 0, sy: 0, ex: 0, ey: 0 };
  let blocks = {};
  let blockOrder = [];

  // long-press-to-move state (Edit Map mode only)
  let longPressTimer = null;
  let pendingEditBid = null;
  let blockDrag = {
    active: false,
    bid: null,
    startX: 0,
    startY: 0,
    origPts: null,
  };

  const $ = (id) => document.getElementById(id);
  let canvasWrap, cv, ctx, plotViewEl;

  // ── GEOMETRY ─────────────────────────────────────────────
  function uid() {
    return Math.random().toString(36).slice(2, 8);
  }

  function snap(v) {
    return Math.round(v / CELL) * CELL;
  }

  function rectToPoints(x, y, w, h) {
    return [
      { x: x, y: y },
      { x: x + w, y: y },
      { x: x + w, y: y + h },
      { x: x, y: y + h },
    ];
  }

  function ensurePolygon(b) {
    if (!b.points || b.points.length < 3)
      b.points = rectToPoints(b.x, b.y, b.w, b.h);
    return b.points;
  }

  function polyBounds(pts) {
    const xs = pts.map((p) => p.x),
      ys = pts.map((p) => p.y);
    const x = Math.min(...xs),
      y = Math.min(...ys);
    return { x, y, w: Math.max(...xs) - x, h: Math.max(...ys) - y };
  }

  function pointInPoly(px, py, pts) {
    let inside = false;
    for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
      const xi = pts[i].x,
        yi = pts[i].y,
        xj = pts[j].x,
        yj = pts[j].y;
      if (yi > py !== yj > py && px < ((xj - xi) * (py - yi)) / (yj - yi) + xi)
        inside = !inside;
    }
    return inside;
  }

  function dist(a, b) {
    return Math.sqrt((a.x - b.x) ** 2 + (a.y - b.y) ** 2);
  }

  function distToSegment(p, a, b) {
    const dx = b.x - a.x,
      dy = b.y - a.y;
    const lenSq = dx * dx + dy * dy;
    if (lenSq === 0) return dist(p, a);
    let t = ((p.x - a.x) * dx + (p.y - a.y) * dy) / lenSq;
    t = Math.max(0, Math.min(1, t));
    return dist(p, { x: a.x + t * dx, y: a.y + t * dy });
  }

  function projectOntoSegment(p, a, b) {
    const dx = b.x - a.x,
      dy = b.y - a.y;
    const lenSq = dx * dx + dy * dy;
    if (lenSq === 0) return { point: { ...a }, t: 0 };
    const t = Math.max(
      0,
      Math.min(1, ((p.x - a.x) * dx + (p.y - a.y) * dy) / lenSq),
    );
    return { point: { x: snap(a.x + t * dx), y: snap(a.y + t * dy) }, t };
  }

  function closestEdge(pts, px, py) {
    let best = null,
      bestDist = Infinity;
    for (let i = 0; i < pts.length; i++) {
      const a = pts[i],
        b = pts[(i + 1) % pts.length];
      const d = distToSegment({ x: px, y: py }, a, b);
      if (d < bestDist) {
        bestDist = d;
        const proj = projectOntoSegment({ x: px, y: py }, a, b);
        best = { edgeIndex: i, point: proj.point, dist: d };
      }
    }
    return best;
  }

  function blockStats(b) {
    const floors = b.floors || 1;
    const total = b.rows * b.cols * floors;
    const occ = Object.values(b.plots || {}).filter(
      (p) => p.status === "occupied",
    ).length;
    const res = Object.values(b.plots || {}).filter(
      (p) => p.status === "reserved",
    ).length;
    return { total, occ, res, avail: total - occ - res };
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  // ── PLOT NAMING (per-floor scheme) ───────────────────────
  // Plots are stored under a stable internal key ("floor_r_c", 0-indexed)
  // so changing a floor's scheme later never loses occupant data — only
  // the DISPLAY label is recomputed.
  function plotKey(floor, r, c) {
    return `${floor}_${r}_${c}`;
  }

  function colLetters(n) {
    // 1 -> A, 2 -> B ... 26 -> Z, 27 -> AA, spreadsheet-style
    let s = "";
    while (n > 0) {
      n--;
      s = String.fromCharCode(65 + (n % 26)) + s;
      n = Math.floor(n / 26);
    }
    return s;
  }

  function lettersToNum(str) {
    str = (str || "A").toUpperCase().replace(/[^A-Z]/g, "") || "A";
    let n = 0;
    for (let i = 0; i < str.length; i++) n = n * 26 + (str.charCodeAt(i) - 64);
    return n || 1;
  }

  function getFloorConfig(b, floorNum) {
    return (
      (b.floorConfigs && b.floorConfigs[floorNum]) || {
        scheme: DEFAULT_SCHEME,
        rowStart: "1",
        colStart: "A",
      }
    );
  }

  // scheme: "1A_*" = row numeric, col alpha · "A1_*" = row alpha, col numeric
  // "*_desc" flips the counting direction to start from the bottom-right.
  function getPlotLabel(b, floorNum, r, c) {
    const fc = getFloorConfig(b, floorNum);
    const scheme = fc.scheme || DEFAULT_SCHEME;
    const desc = scheme.endsWith("desc");
    const rowIsAlpha = scheme.startsWith("A1");
    const colIsAlpha = !rowIsAlpha;

    const rowIdx = desc ? b.rows - 1 - r : r;
    const colIdx = desc ? b.cols - 1 - c : c;

    const rowBase = rowIsAlpha
      ? lettersToNum(fc.rowStart)
      : parseInt(fc.rowStart) || 1;
    const colBase = colIsAlpha
      ? lettersToNum(fc.colStart)
      : parseInt(fc.colStart) || 1;

    const rowVal = rowBase + rowIdx;
    const colVal = colBase + colIdx;

    const rowLabel = rowIsAlpha ? colLetters(rowVal) : String(rowVal);
    const colLabel = colIsAlpha ? colLetters(colVal) : String(colVal);

    const cellLabel = `${rowLabel}${colLabel}`;
    return (b.floors || 1) > 1 ? `F${floorNum}-${cellLabel}` : cellLabel;
  }

  // migrate very old saved plots ("R1-C1", single floor, no prefix)
  function migrateLegacyPlots(b) {
    if (!b.plots) return;
    const migrated = {};
    let changed = false;
    Object.entries(b.plots).forEach(([k, v]) => {
      const m = /^R(\d+)-C(\d+)$/.exec(k);
      if (m) {
        migrated[plotKey(1, parseInt(m[1]) - 1, parseInt(m[2]) - 1)] = v;
        changed = true;
      } else {
        migrated[k] = v;
      }
    });
    if (changed) b.plots = migrated;
  }

  // ── CANVAS SIZE (DPI Fix) ────────────────────────────────
  function syncCanvasSize() {
    const w = canvasWrap.clientWidth,
      h = canvasWrap.clientHeight;
    const dpr = window.devicePixelRatio || 1;

    if (cv.width !== w * dpr || cv.height !== h * dpr) {
      cv.width = w * dpr;
      cv.height = h * dpr;
      cv.style.width = `${w}px`;
      cv.style.height = `${h}px`;
      ctx.scale(dpr, dpr);
    }
  }

  // ── PERSISTENCE ──────────────────────────────────────────
  function persistSave() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({ blocks, blockOrder }));
      flashSaved();
    } catch (e) {
      console.warn("Save failed", e);
    }
  }

  function persistLoad() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (raw) {
        const d = JSON.parse(raw);
        blocks = d.blocks || {};
        blockOrder = d.blockOrder || [];
        blockOrder.forEach((bid) => {
          const b = blocks[bid];
          ensurePolygon(b);
          if (!b.floors) b.floors = 1;
          if (!b.floorConfigs) {
            b.floorConfigs = {};
            for (let f = 1; f <= b.floors; f++) {
              b.floorConfigs[f] = {
                scheme: DEFAULT_SCHEME,
                rowStart: "1",
                colStart: "A",
              };
            }
          }
          migrateLegacyPlots(b);
        });
      }
    } catch (e) {
      blocks = {};
      blockOrder = [];
    }
    $("mapLoading").style.display = "none";
    syncCanvasSize();
    draw();
    updateStatus();
    setHint('Click "Edit map" to start building your cemetery map.');
  }

  function flashSaved() {
    setHint("Map saved successfully!");
    setTimeout(() => setHint(""), 3000);
  }

  // ── DRAW ─────────────────────────────────────────────────
  function draw() {
    syncCanvasSize();
    // Use client dimensions since context is scaled
    ctx.clearRect(0, 0, canvasWrap.clientWidth, canvasWrap.clientHeight);
    if (view !== "map") return;

    if (!blockOrder.length) {
      ctx.fillStyle = "#94a3b8";
      ctx.font = "13px Inter, sans-serif";
      ctx.textAlign = "center";
      ctx.fillText(
        mapEditMode
          ? 'Click "Add block" then drag to draw a block.'
          : 'No blocks yet. Click "Edit map" to get started.',
        canvasWrap.clientWidth / 2,
        canvasWrap.clientHeight / 2,
      );
      return;
    }

    blockOrder.forEach((bid) => {
      const b = blocks[bid];
      const pts = ensurePolygon(b);
      const hov = hoveredBlock === bid;
      const isRes = resizingBid === bid;
      const isResh = reshapeMode && reshapeBid === bid;
      const isDrag = blockDrag.active && blockDrag.bid === bid;
      const { occ, res, avail } = blockStats(b);
      const bounds = polyBounds(pts);
      const cx = bounds.x + bounds.w / 2;
      const cy = bounds.y + bounds.h / 2;

      ctx.globalAlpha =
        reshapeMode && !isResh ? 0.25 : isRes ? 0.4 : isDrag ? 0.75 : 1;

      ctx.beginPath();
      pts.forEach((p, i) =>
        i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y),
      );
      ctx.closePath();

      ctx.fillStyle = isResh ? "#fef9c3" : isDrag ? "#ede9fe" : "#dbeafe";
      ctx.strokeStyle = isResh
        ? "#f59e0b"
        : isRes
          ? "#ef4444"
          : isDrag
            ? "#8b5cf6"
            : hov
              ? "#1d4ed8"
              : "#3b82f6";
      ctx.lineWidth = isResh || hov || isRes || isDrag ? 2 : 1;
      ctx.fill();
      ctx.stroke();

      ctx.fillStyle = "#1e3a5f";
      ctx.font = "500 13px Inter, sans-serif";
      ctx.textAlign = "center";
      ctx.fillText(b.name, cx, cy - 14);

      ctx.fillStyle = "#2563eb";
      ctx.font = "10px Inter, sans-serif";
      ctx.fillText(
        `${b.rows}\u00d7${b.cols}${(b.floors || 1) > 1 ? ` \u00d7 ${b.floors}fl` : ""} plots`,
        cx,
        cy + 2,
      );
      ctx.fillText(
        `${occ} occ \u00b7 ${res} res \u00b7 ${avail} avail`,
        cx,
        cy + 16,
      );

      if (!reshapeMode && !isDrag) {
        ctx.fillStyle = isRes ? "#ef4444" : mapEditMode ? "#3b82f6" : "#2563eb";
        ctx.fillText(
          isRes
            ? "drag to resize\u2026"
            : mapEditMode
              ? "click to edit \u00b7 hold to move"
              : "click to view",
          cx,
          bounds.y + bounds.h - 8,
        );
      }

      if (isResh) {
        pts.forEach((p, i) => {
          const next = pts[(i + 1) % pts.length];
          const mx2 = (p.x + next.x) / 2,
            my2 = (p.y + next.y) / 2;
          ctx.beginPath();
          ctx.arc(mx2, my2, 3, 0, Math.PI * 2);
          ctx.fillStyle = "rgba(99,102,241,0.5)";
          ctx.fill();
        });

        pts.forEach((p) => {
          ctx.beginPath();
          ctx.arc(p.x, p.y, 5, 0, Math.PI * 2);
          ctx.fillStyle = "#6366f1";
          ctx.strokeStyle = "#fff";
          ctx.lineWidth = 2;
          ctx.fill();
          ctx.stroke();
        });

        if (reshapeTool === "cut") {
          if (cutFirstPoint) {
            ctx.beginPath();
            ctx.arc(cutFirstPoint.x, cutFirstPoint.y, 7, 0, Math.PI * 2);
            ctx.fillStyle = "#ef4444";
            ctx.strokeStyle = "#fff";
            ctx.lineWidth = 2;
            ctx.fill();
            ctx.stroke();

            ctx.beginPath();
            ctx.moveTo(cutFirstPoint.x, cutFirstPoint.y);
            ctx.lineTo(mousePos.x, mousePos.y);
            ctx.strokeStyle = "#ef4444";
            ctx.lineWidth = 1.5;
            ctx.setLineDash([4, 3]);
            ctx.stroke();
            ctx.setLineDash([]);
          }
          const edge = closestEdge(pts, mousePos.x, mousePos.y);
          if (edge && edge.dist < 20) {
            ctx.beginPath();
            ctx.arc(edge.point.x, edge.point.y, 6, 0, Math.PI * 2);
            ctx.fillStyle = cutFirstPoint ? "#ef4444" : "#f97316";
            ctx.strokeStyle = "#fff";
            ctx.lineWidth = 2;
            ctx.fill();
            ctx.stroke();
          }
        }

        if (reshapeTool === "draw" && drawPoints.length > 0) {
          ctx.beginPath();
          ctx.moveTo(drawPoints[0].x, drawPoints[0].y);
          drawPoints.forEach((p, i) => {
            if (i > 0) ctx.lineTo(p.x, p.y);
          });
          ctx.lineTo(mousePos.x, mousePos.y);
          ctx.strokeStyle = "#22c55e";
          ctx.lineWidth = 2;
          ctx.setLineDash([5, 3]);
          ctx.stroke();
          ctx.setLineDash([]);

          drawPoints.forEach((p) => {
            ctx.beginPath();
            ctx.arc(p.x, p.y, 5, 0, Math.PI * 2);
            ctx.fillStyle = "#22c55e";
            ctx.strokeStyle = "#fff";
            ctx.lineWidth = 2;
            ctx.fill();
            ctx.stroke();
          });

          const edge = closestEdge(pts, mousePos.x, mousePos.y);
          if (edge && edge.dist < 20) {
            ctx.beginPath();
            ctx.arc(edge.point.x, edge.point.y, 6, 0, Math.PI * 2);
            ctx.fillStyle = "#22c55e";
            ctx.strokeStyle = "#fff";
            ctx.lineWidth = 2;
            ctx.fill();
            ctx.stroke();
          }
        } else if (reshapeTool === "draw" && drawPoints.length === 0) {
          const edge = closestEdge(pts, mousePos.x, mousePos.y);
          if (edge && edge.dist < 20) {
            ctx.beginPath();
            ctx.arc(edge.point.x, edge.point.y, 6, 0, Math.PI * 2);
            ctx.fillStyle = "#22c55e";
            ctx.strokeStyle = "#fff";
            ctx.lineWidth = 2;
            ctx.fill();
            ctx.stroke();
          }
        }
      }
      ctx.globalAlpha = 1;
    });

    if (drag.active) {
      const x = Math.min(drag.sx, drag.ex),
        y = Math.min(drag.sy, drag.ey);
      const w = Math.max(snap(Math.abs(drag.ex - drag.sx)), CELL * 2);
      const h = Math.max(snap(Math.abs(drag.ey - drag.sy)), CELL * 2);
      ctx.fillStyle = "rgba(59,130,246,0.10)";
      ctx.strokeStyle = "#3b82f6";
      ctx.lineWidth = 1.5;
      ctx.setLineDash([4, 3]);
      ctx.beginPath();
      ctx.rect(x, y, w, h);
      ctx.fill();
      ctx.stroke();
      ctx.setLineDash([]);
      ctx.fillStyle = "#1e3a5f";
      ctx.font = "11px Inter, sans-serif";
      ctx.textAlign = "center";
      ctx.fillText(
        `${Math.max(1, Math.round(h / CELL))} rows \u00d7 ${Math.max(1, Math.round(w / CELL))} cols`,
        x + w / 2,
        y + h / 2,
      );
    }
  }

  function hitBlock(mx, my) {
    return blockOrder
      .slice()
      .reverse()
      .find((bid) => pointInPoly(mx, my, ensurePolygon(blocks[bid])));
  }

  function getXY(e) {
    const r = cv.getBoundingClientRect();
    const s = e.touches ? e.touches[0] : e;
    return { x: s.clientX - r.left, y: s.clientY - r.top };
  }

  // ── RESHAPE LOGIC ────────────────────────────────────────
  function handleCutClick(mx, my) {
    const pts = ensurePolygon(blocks[reshapeBid]);
    const edge = closestEdge(pts, mx, my);
    if (!edge || edge.dist > 24) return;

    if (!cutFirstPoint) {
      cutFirstPoint = { ...edge.point, edgeIndex: edge.edgeIndex };
      draw();
      return;
    }

    const p1 = cutFirstPoint;
    const p2 = { ...edge.point, edgeIndex: edge.edgeIndex };
    cutFirstPoint = null;

    let workPts = [...pts];
    let idx1 = p1.edgeIndex,
      idx2 = p2.edgeIndex;
    let pt1 = { x: p1.x, y: p1.y },
      pt2 = { x: p2.x, y: p2.y };

    // Same-edge fix: Distance sorting to avoid self-intersecting shapes
    if (idx1 === idx2) {
      const edgeStart = pts[idx1];
      if (dist(edgeStart, pt1) > dist(edgeStart, pt2)) {
        [pt1, pt2] = [pt2, pt1];
      }
    } else if (idx2 < idx1) {
      [idx1, idx2] = [idx2, idx1];
      [pt1, pt2] = [pt2, pt1];
    }

    workPts.splice(idx2 + 1, 0, { ...pt2 });
    workPts.splice(idx1 + 1, 0, { ...pt1 });

    const i1 = idx1 + 1,
      i2 = idx2 + 2;
    const polyA = [],
      polyB = [];

    for (let i = i1; ; i = (i + 1) % workPts.length) {
      polyA.push(workPts[i]);
      if (i === i2) break;
    }
    for (let i = i2; ; i = (i + 1) % workPts.length) {
      polyB.push(workPts[i]);
      if (i === i1) break;
    }

    const areaOf = (arr) => {
      const b = polyBounds(arr);
      return b.w * b.h;
    };
    const kept = areaOf(polyA) >= areaOf(polyB) ? polyA : polyB;

    if (kept.length < 3) {
      draw();
      return;
    }

    reshapeHistory.push([...pts]);
    blocks[reshapeBid].points = kept;
    draw();
  }

  function handleDrawClick(mx, my) {
    const pts = ensurePolygon(blocks[reshapeBid]);
    const edge = closestEdge(pts, mx, my);
    const onEdge = edge && edge.dist < 24;

    if (drawPoints.length === 0) {
      if (!onEdge) return;
      drawPoints = [{ ...edge.point, edgeIndex: edge.edgeIndex, onEdge: true }];
      draw();
      return;
    }

    if (onEdge && drawPoints.length >= 1) {
      const startPt = drawPoints[0];
      if (dist(edge.point, startPt) < 10 && drawPoints.length < 2) return;

      const endPt = { ...edge.point, edgeIndex: edge.edgeIndex };
      let workPts = [...pts];
      let idx1 = startPt.edgeIndex,
        idx2 = endPt.edgeIndex;
      let pt1 = { x: startPt.x, y: startPt.y },
        pt2 = { x: endPt.x, y: endPt.y };
      const midPoints = drawPoints.slice(1).map((p) => ({ x: p.x, y: p.y }));

      if (idx2 < idx1) {
        [idx1, idx2] = [idx2, idx1];
        [pt1, pt2] = [pt2, pt1];
        midPoints.reverse();
      }

      workPts.splice(idx2 + 1, 0, { ...pt2 });
      workPts.splice(idx1 + 1, 0, { ...pt1 });

      const i1 = idx1 + 1,
        i2 = idx2 + 2;
      const polyB = [];

      for (let i = i2; ; i = (i + 1) % workPts.length) {
        polyB.push(workPts[i]);
        if (i === i1) break;
      }

      const newPoly = [
        pt1,
        ...midPoints,
        pt2,
        ...polyB.slice(1, polyB.length - 1),
      ];

      if (newPoly.length < 3) {
        drawPoints = [];
        draw();
        return;
      }

      reshapeHistory.push([...pts]);
      blocks[reshapeBid].points = newPoly;
      drawPoints = [];
      draw();
      return;
    }

    drawPoints.push({ x: snap(mx), y: snap(my), onEdge: false });
    draw();
  }

  // ── CANVAS EVENTS ────────────────────────────────────────
  function initCanvasEvents() {
    const handleMove = (e) => {
      if (view !== "map") return;
      // Prevent browser scroll when dragging/drawing on touch screens
      if (
        drag.active ||
        blockDrag.active ||
        (reshapeMode && drawPoints.length > 0)
      ) {
        if (e.cancelable) e.preventDefault();
      }

      const { x, y } = getXY(e);
      mousePos = { x, y };

      if (blockDrag.active) {
        const dx = snap(x - blockDrag.startX);
        const dy = snap(y - blockDrag.startY);
        blocks[blockDrag.bid].points = blockDrag.origPts.map((p) => ({
          x: p.x + dx,
          y: p.y + dy,
        }));
        draw();
        return;
      }

      if (drag.active) {
        drag.ex = snap(x);
        drag.ey = snap(y);
        draw();
        return;
      }

      if (reshapeMode) {
        draw();
        return;
      }

      const bid = hitBlock(x, y);
      if (bid !== hoveredBlock) {
        hoveredBlock = bid;
        draw();
      }
      canvasWrap.style.cursor = bid
        ? mapEditMode
          ? "grab"
          : "pointer"
        : addMode || resizeMode
          ? "crosshair"
          : "default";
    };

    const handleDown = (e) => {
      if (view !== "map") return;
      const { x, y } = getXY(e);

      if (reshapeMode) {
        if (!reshapeBid) return;
        if (reshapeTool === "cut") handleCutClick(x, y);
        else if (reshapeTool === "draw") handleDrawClick(x, y);
        return;
      }

      if (addMode || resizeMode) {
        drag = {
          active: true,
          sx: snap(x),
          sy: snap(y),
          ex: snap(x),
          ey: snap(y),
        };
        return;
      }

      const bid = hitBlock(x, y);
      if (!bid) return;

      if (mapEditMode) {
        // Quick click -> edit modal. Press-and-hold -> drag to reposition.
        pendingEditBid = bid;
        clearTimeout(longPressTimer);
        longPressTimer = setTimeout(() => {
          pendingEditBid = null;
          blockDrag = {
            active: true,
            bid,
            startX: x,
            startY: y,
            origPts: JSON.parse(JSON.stringify(ensurePolygon(blocks[bid]))),
          };
          canvasWrap.style.cursor = "grabbing";
          setHint("Dragging block \u2014 release to drop.");
          draw();
        }, LONG_PRESS_MS);
        return;
      }

      openBlockView(bid);
    };

    const handleUp = (e) => {
      if (blockDrag.active) {
        blockDrag.active = false;
        blockDrag.bid = null;
        blockDrag.origPts = null;
        canvasWrap.style.cursor = "default";
        setHint(
          'Click "Add block" to draw a block, or click a block to edit it.',
        );
        draw();
        return;
      }

      if (longPressTimer) {
        clearTimeout(longPressTimer);
        longPressTimer = null;
      }

      if (pendingEditBid) {
        const bid = pendingEditBid;
        pendingEditBid = null;
        openBlockEditModal(bid);
        return;
      }

      if (!drag.active) return;

      // On touchend we don't have active touches[0], use tracked drag coords
      let targetX = drag.ex,
        targetY = drag.ey;
      if (e.type !== "touchend" && e.clientX) {
        const pos = getXY(e);
        targetX = snap(pos.x);
        targetY = snap(pos.y);
      }

      drag.ex = targetX;
      drag.ey = targetY;
      const rx = Math.min(drag.sx, drag.ex),
        ry = Math.min(drag.sy, drag.ey);
      const rw = Math.max(snap(Math.abs(drag.ex - drag.sx)), CELL * 2);
      const rh = Math.max(snap(Math.abs(drag.ey - drag.sy)), CELL * 2);

      drag.active = false;
      if (resizeMode && resizingBid) finishResize(rx, ry, rw, rh);
      else if (addMode && rw >= CELL * 2 && rh >= CELL * 2) {
        openNewBlockModal(
          rx,
          ry,
          rw,
          rh,
          Math.max(1, Math.round(rh / CELL)),
          Math.max(1, Math.round(rw / CELL)),
        );
      } else draw();
    };

    // Mouse bindings
    cv.addEventListener("mousemove", handleMove);
    cv.addEventListener("mousedown", handleDown);
    cv.addEventListener("mouseup", handleUp);

    // Touch bindings (Crucial fix for Mobile/iPad)
    cv.addEventListener("touchmove", handleMove, { passive: false });
    cv.addEventListener("touchstart", handleDown, { passive: false });
    cv.addEventListener("touchend", handleUp);

    cv.addEventListener("dblclick", (e) => {
      if (!reshapeMode || reshapeTool !== "draw" || drawPoints.length < 2)
        return;
      const { x, y } = getXY(e);
      const pts = ensurePolygon(blocks[reshapeBid]);
      const edge = closestEdge(pts, x, y);
      if (edge && edge.dist < 30) handleDrawClick(x, y);
    });

    window.addEventListener("mouseup", (e) => {
      if (drag.active || blockDrag.active || pendingEditBid) handleUp(e);
    });

    window.addEventListener("resize", () => {
      syncCanvasSize();
      draw();
    });

    window.addEventListener("keydown", (e) => {
      if (e.key === "Escape") {
        if (reshapeMode) {
          cutFirstPoint = null;
          drawPoints = [];
          draw();
        }
      }
    });
  }

  // ── BUTTON WIRING ────────────────────────────────────────
  function initButtons() {
    $("btnEditMap").addEventListener("click", enterEditMap);

    $("btnSaveMap").addEventListener("click", () => {
      persistSave();
      exitEditMap(); // Fixed: Exits mode properly
    });

    $("btnAddBlock").addEventListener("click", startAdd);
    $("btnCancelAdd").addEventListener("click", cancelAdd);
    $("mapSearchInput").addEventListener("input", (e) =>
      doSearch(e.target.value),
    );
    $("modalOverlay").addEventListener("click", function (e) {
      if (e.target === this) closeModal();
    });
  }

  // ── EDIT MAP MODE ────────────────────────────────────────
  function enterEditMap() {
    mapEditMode = true;
    $("btnEditMap").style.display = "none";
    $("btnSaveMap").style.display = "";
    $("btnAddBlock").style.display = "";
    setHint(
      'Click "Add block" to draw a block, click a block to edit it, or press and hold a block to move it.',
    );
    draw();
  }

  function exitEditMap() {
    if (reshapeMode) _clearReshape();
    clearTimeout(longPressTimer);
    longPressTimer = null;
    pendingEditBid = null;
    blockDrag = {
      active: false,
      bid: null,
      startX: 0,
      startY: 0,
      origPts: null,
    };
    mapEditMode = false;
    addMode = false;
    resizeMode = false;
    resizingBid = null;
    hoveredBlock = null;
    canvasWrap.style.cursor = "default";
    $("btnEditMap").style.display = "";
    $("btnSaveMap").style.display = "none";
    $("btnAddBlock").style.display = "none";
    $("btnCancelAdd").style.display = "none";
    setHint("");
    if (view === "block") renderPlotGrid();
    else draw();
  }

  function startAdd() {
    if (reshapeMode) return;
    addMode = true;
    resizeMode = false;
    hoveredBlock = null;
    canvasWrap.style.cursor = "crosshair";
    $("btnAddBlock").style.display = "none";
    $("btnCancelAdd").style.display = "";
    $("btnCancelAdd").textContent = "Cancel draw";
    setHint("Drag on the canvas to draw the block shape.");
  }

  function cancelAdd() {
    addMode = false;
    resizeMode = false;
    resizingBid = null;
    drag.active = false;
    canvasWrap.style.cursor = "default";
    $("btnAddBlock").style.display = "";
    $("btnCancelAdd").style.display = "none";
    setHint('Click "Add block" to draw a block, or click a block to edit it.');
    draw();
  }

  function setHint(t) {
    if ($("mapHint")) $("mapHint").textContent = t;
  }

  // ── RESHAPE MODE ─────────────────────────────────────────
  function enterReshapeMode(bid) {
    reshapeMode = true;
    reshapeBid = bid;
    reshapeTool = "cut";
    reshapeHistory = [];
    cutFirstPoint = null;
    drawPoints = [];
    addMode = false;
    resizeMode = false;
    resizingBid = null;
    drag.active = false;
    $("btnAddBlock").style.display = "none";
    $("btnCancelAdd").style.display = "none";
    $("btnCutLine").style.display = "";
    $("btnDrawLine").style.display = "";
    $("btnDoneReshape").style.display = "";
    $("btnUndoReshape").style.display = "";
    _highlightReshapeTool();
    canvasWrap.style.cursor = "crosshair";
    closeModal();
    setHint("CUT LINE: click two points on edges to cut away a section.");
    draw();
  }

  function exitReshapeMode() {
    _clearReshape();
    $("btnAddBlock").style.display = "";
    $("btnCutLine").style.display = "none";
    $("btnDrawLine").style.display = "none";
    $("btnDoneReshape").style.display = "none";
    $("btnUndoReshape").style.display = "none";
    canvasWrap.style.cursor = "default";
    setHint('Click "Add block" to draw a block, or click a block to edit it.');
    draw();
  }

  function setReshapeTool(tool) {
    reshapeTool = tool;
    cutFirstPoint = null;
    drawPoints = [];
    _highlightReshapeTool();
    if (tool === "cut")
      setHint(
        "CUT LINE: click a point on an edge, then click a second point to slice.",
      );
    if (tool === "draw")
      setHint(
        "DRAW LINE: click edge → add waypoints → click edge to finish shape.",
      );
    draw();
  }

  function _highlightReshapeTool() {
    $("btnCutLine").classList.toggle("active", reshapeTool === "cut");
    $("btnDrawLine").classList.toggle("active", reshapeTool === "draw");
  }

  function undoReshape() {
    if (!reshapeHistory.length) return;
    blocks[reshapeBid].points = reshapeHistory.pop();
    cutFirstPoint = null;
    drawPoints = [];
    draw();
  }

  function _clearReshape() {
    reshapeMode = false;
    reshapeBid = null;
    reshapeTool = "cut";
    reshapeHistory = [];
    cutFirstPoint = null;
    drawPoints = [];
    $("btnCutLine").style.display = "none";
    $("btnDrawLine").style.display = "none";
    $("btnDoneReshape").style.display = "none";
    $("btnUndoReshape").style.display = "none";
  }

  // ── BLOCK MODALS ─────────────────────────────────────────
  function openNewBlockModal(bx, by, bw, bh, rows, cols) {
    addMode = false;
    drag.active = false;
    canvasWrap.style.cursor = "default";
    $("btnAddBlock").style.display = "";
    $("btnCancelAdd").style.display = "none";
    $("modalTitle").textContent = "Name this block";
    $("modalBody").innerHTML = `
      <label>Block name</label>
      <input type="text" id="fBn" placeholder="e.g. Block A">
      <div class="modalRowPair" style="margin-top:10px">
        <div><label>Columns</label><input type="number" id="fBc" min="1" max="30" value="${cols}"></div>
        <div><label>Rows</label><input type="number" id="fBr" min="1" max="30" value="${rows}"></div>
      </div>
      <label style="margin-top:10px">Burial Type</label>
      <select id="fBt">
        <option value="Niche">Niche</option>
        <option value="Wall">Wall</option>
        <option value="Bone Chamber">Bone Chamber</option>
        <option value="Lawn/Ground">Lawn/Ground</option>
        <option value="Unmapped Area">Unmapped Area</option>
        <option value="Private/Owned">Private/Owned</option>
      </select>
      <label style="margin-top:10px">Floor Level</label>
      <input type="number" id="fBfl" min="1" max="20" value="1">
      <p style="font-size:11px;color:#94a3b8;margin-top:4px">Number of floors this block has (e.g. a multi-level columbarium). Each floor's numbering scheme can be changed individually later from its plot view.</p>
      <hr class="modalDivider">
      <label>Scheme</label>
      <select id="fBScheme">
        <option value="1A_asc">1A Ascending</option>
        <option value="1A_desc">1A Descending</option>
        <option value="A1_asc">A1 Ascending</option>
        <option value="A1_desc">A1 Descending</option>
      </select>
      <div class="modalRowPair" style="margin-top:10px">
        <div><label id="fBRowLbl">Row start</label><input type="text" id="fBRowStart" value="1"></div>
        <div><label id="fBColLbl">Column start</label><input type="text" id="fBColStart" value="A"></div>
      </div>
      <p id="fBSchemePreview" style="font-size:12px;color:#3b82f6;margin-top:6px;font-weight:500"></p>
      <p style="font-size:11px;color:#94a3b8;margin-top:8px">Drawn area: ${bw}&times;${bh}px &mdash; adjust divisions above.</p>`;

    $("modalConfirm").textContent = "Save";
    $("modalConfirm").className = "btnPrimary";
    $("modalConfirm").style.display = "";
    $("modalConfirm").onclick = () => {
      const name = $("fBn").value.trim();
      const c = parseInt($("fBc").value) || cols;
      const r = parseInt($("fBr").value) || rows;
      const floors = Math.max(1, parseInt($("fBfl").value) || 1);
      const burialType = $("fBt").value;
      const scheme = $("fBScheme").value;
      const rowStart = $("fBRowStart").value.trim() || "1";
      const colStart = $("fBColStart").value.trim() || "A";
      if (!name) {
        $("fBn").focus();
        return;
      }
      const bid = uid();
      const floorConfigs = {};
      for (let f = 1; f <= floors; f++) {
        floorConfigs[f] = { scheme, rowStart, colStart };
      }
      blocks[bid] = {
        name,
        x: bx,
        y: by,
        w: bw,
        h: bh,
        rows: r,
        cols: c,
        burialType,
        floors,
        floorConfigs,
        plots: {},
        points: rectToPoints(bx, by, bw, bh),
      };
      blockOrder.push(bid);
      closeModal();
      requestAnimationFrame(() =>
        requestAnimationFrame(() => {
          syncCanvasSize();
          draw();
          updateStatus();
        }),
      );
      setHint(
        'Click "Add block" to draw a block, or click a block to edit it.',
      );
    };
    $("modalCancel").textContent = "Cancel";
    $("modalCancel").onclick = () => {
      closeModal();
      draw();
    };
    showModal();
    setTimeout(() => $("fBn") && $("fBn").focus(), 60);

    const updateSchemePreview = () => {
      const scheme = $("fBScheme").value;
      const rowIsAlpha = scheme.startsWith("A1");
      $("fBRowLbl").textContent = rowIsAlpha
        ? "Row start (letter)"
        : "Row start (number)";
      $("fBColLbl").textContent = rowIsAlpha
        ? "Column start (number)"
        : "Column start (letter)";
      const sample = {
        rows: Math.max(3, rows),
        cols: Math.max(3, cols),
        floors: 1,
        floorConfigs: {
          1: {
            scheme,
            rowStart: $("fBRowStart").value || "1",
            colStart: $("fBColStart").value || "A",
          },
        },
      };
      $("fBSchemePreview").textContent =
        `Preview \u2014 first plot: "${getPlotLabel(sample, 1, 0, 0)}"`;
    };
    ["fBScheme", "fBRowStart", "fBColStart"].forEach((id) =>
      $(id).addEventListener("input", updateSchemePreview),
    );
    updateSchemePreview();
  }

  function openBlockEditModal(bid) {
    curBlock = bid;
    const b = blocks[bid];
    const { occ, res, avail, total } = blockStats(b);
    $("modalTitle").textContent = `Edit block \u2014 ${escHtml(b.name)}`;
    $("modalBody").innerHTML = `
      <label>Block name</label>
      <input type="text" id="fEn" value="${escHtml(b.name)}">
      <div class="modalRowPair" style="margin-top:10px">
        <div><label>Columns</label><input type="number" id="fEc" min="1" max="30" value="${b.cols}"></div>
        <div><label>Rows</label><input type="number" id="fEr" min="1" max="30" value="${b.rows}"></div>
      </div>
      <label style="margin-top:10px">Burial Type</label>
      <select id="fEt">
        <option value="Niche" ${b.burialType === "Niche" ? "selected" : ""}>Niche</option>
        <option value="Wall" ${b.burialType === "Wall" ? "selected" : ""}>Wall</option>
        <option value="Bone Chamber" ${b.burialType === "Bone Chamber" ? "selected" : ""}>Bone Chamber</option>
        <option value="Lawn/Ground" ${b.burialType === "Lawn/Ground" ? "selected" : ""}>Lawn/Ground</option>
        <option value="Unmapped Area" ${b.burialType === "Unmapped Area" ? "selected" : ""}>Unmapped Area</option>
        <option value="Private/Owned" ${b.burialType === "Private/Owned" ? "selected" : ""}>Private/Owned</option>
      </select>
      <label style="margin-top:10px">Floor Level</label>
      <input type="number" id="fEfl" min="1" max="20" value="${b.floors || 1}">
      <p style="font-size:11px;color:#94a3b8;margin-top:6px">${total} plots &middot; ${occ} occ &middot; ${res} res &middot; ${avail} avail</p>
      <hr class="modalDivider">
      <button class="reshapeModalBtn" style="margin-top:10px" onclick="MAP.enterReshapeMode('${bid}')">&#9998; Reshape block (freeform)</button>
      <button class="warnBtn" onclick="MAP.activateResize('${bid}')">Resize block on canvas</button>
      <div class="modalExtraRow" style="margin-top:8px">
        <button style="background:#3b82f6;color:#fff;border:1px solid #3b82f6;border-radius:6px;padding:7px 10px;font-size:12px;cursor:pointer;font-family:Inter,sans-serif;" onclick="MAP.closeModal();MAP.openBlockView('${bid}')">View plots</button>
        <button style="background:#fff;color:#ef4444;border:1px solid #fca5a5;border-radius:6px;padding:7px 10px;font-size:12px;cursor:pointer;font-family:Inter,sans-serif;" onclick="MAP.deleteBlock('${bid}')">Delete block</button>
      </div>`;

    $("modalConfirm").textContent = "Save changes";
    $("modalConfirm").className = "btnPrimary";
    $("modalConfirm").style.display = "";
    $("modalConfirm").onclick = () => {
      const name = $("fEn").value.trim();
      const cols = parseInt($("fEc").value) || b.cols;
      const rows = parseInt($("fEr").value) || b.rows;
      const floors = Math.max(1, parseInt($("fEfl").value) || b.floors || 1);
      const burialType = $("fEt").value;
      if (!name) return;
      blocks[bid].name = name;
      blocks[bid].cols = cols;
      blocks[bid].rows = rows;
      blocks[bid].burialType = burialType;
      if (!blocks[bid].floorConfigs) blocks[bid].floorConfigs = {};
      for (let f = 1; f <= floors; f++) {
        if (!blocks[bid].floorConfigs[f]) {
          blocks[bid].floorConfigs[f] = {
            scheme: DEFAULT_SCHEME,
            rowStart: "1",
            colStart: "A",
          };
        }
      }
      blocks[bid].floors = floors;
      closeModal();
      draw();
      updateStatus();
    };
    $("modalCancel").textContent = "Cancel";
    $("modalCancel").onclick = closeModal;
    showModal();
    setTimeout(() => $("fEn") && $("fEn").focus(), 60);
  }

  // ── FLOOR NUMBERING SCHEME MODAL ──────────────────────────
  function openFloorSchemeModal() {
    const b = blocks[curBlock];
    const fc = getFloorConfig(b, curFloor);
    $("modalTitle").textContent =
      ((b.floors || 1) > 1 ? `Floor ${curFloor} \u2014 ` : "") +
      "Numbering scheme";
    $("modalBody").innerHTML = `
      <label>Scheme</label>
      <select id="fScScheme">
        <option value="1A_asc" ${fc.scheme === "1A_asc" ? "selected" : ""}>1A Ascending</option>
        <option value="1A_desc" ${fc.scheme === "1A_desc" ? "selected" : ""}>1A Descending</option>
        <option value="A1_asc" ${fc.scheme === "A1_asc" ? "selected" : ""}>A1 Ascending</option>
        <option value="A1_desc" ${fc.scheme === "A1_desc" ? "selected" : ""}>A1 Descending</option>
      </select>
      <div class="modalRowPair" style="margin-top:10px">
        <div><label id="fScRowLbl">Row start</label><input type="text" id="fScRowStart" value="${escHtml(fc.rowStart)}"></div>
        <div><label id="fScColLbl">Column start</label><input type="text" id="fScColStart" value="${escHtml(fc.colStart)}"></div>
      </div>
      <p style="font-size:11px;color:#94a3b8;margin-top:8px">Choose what letter or number row 1 / column 1 should start at for this floor.</p>
      <p id="fScPreview" style="font-size:12px;color:#3b82f6;margin-top:6px;font-weight:500"></p>`;

    $("modalConfirm").textContent = "Save";
    $("modalConfirm").className = "btnPrimary";
    $("modalConfirm").style.display = "";
    $("modalConfirm").onclick = () => {
      const scheme = $("fScScheme").value;
      const rowStart = $("fScRowStart").value.trim() || "1";
      const colStart = $("fScColStart").value.trim() || "A";
      if (!blocks[curBlock].floorConfigs) blocks[curBlock].floorConfigs = {};
      blocks[curBlock].floorConfigs[curFloor] = { scheme, rowStart, colStart };
      closeModal();
      renderPlotGrid();
    };
    $("modalCancel").textContent = "Cancel";
    $("modalCancel").onclick = closeModal;
    showModal();

    const updatePreview = () => {
      const scheme = $("fScScheme").value;
      const rowIsAlpha = scheme.startsWith("A1");
      $("fScRowLbl").textContent = rowIsAlpha
        ? "Row start (letter)"
        : "Row start (number)";
      $("fScColLbl").textContent = rowIsAlpha
        ? "Column start (number)"
        : "Column start (letter)";
      const sample = {
        rows: Math.max(3, b.rows),
        cols: Math.max(3, b.cols),
        floors: b.floors,
        floorConfigs: {
          [curFloor]: {
            scheme,
            rowStart: $("fScRowStart").value || "1",
            colStart: $("fScColStart").value || "A",
          },
        },
      };
      $("fScPreview").textContent =
        `Preview \u2014 first plot: "${getPlotLabel(sample, curFloor, 0, 0)}"`;
    };
    ["fScScheme", "fScRowStart", "fScColStart"].forEach((id) =>
      $(id).addEventListener("input", updatePreview),
    );
    updatePreview();
  }

  function activateResize(bid) {
    closeModal();
    resizingBid = bid;
    resizeMode = true;
    addMode = false;
    hoveredBlock = null;
    canvasWrap.style.cursor = "crosshair";
    $("btnAddBlock").style.display = "none";
    $("btnCancelAdd").style.display = "";
    $("btnCancelAdd").textContent = "Cancel resize";
    setHint(`Drag to set the new size of \u201c${blocks[bid].name}\u201d.`);
    draw();
  }

  function finishResize(rx, ry, rw, rh) {
    const bid = resizingBid;
    blocks[bid].x = rx;
    blocks[bid].y = ry;
    blocks[bid].w = rw;
    blocks[bid].h = rh;
    blocks[bid].cols = Math.max(1, Math.round(rw / CELL));
    blocks[bid].rows = Math.max(1, Math.round(rh / CELL));
    blocks[bid].points = rectToPoints(rx, ry, rw, rh);
    resizeMode = false;
    resizingBid = null;
    canvasWrap.style.cursor = "default";
    $("btnAddBlock").style.display = "";
    $("btnCancelAdd").style.display = "none";
    setHint('Click "Add block" to draw a block, or click a block to edit it.');
    draw();
    updateStatus();
  }

  function deleteBlock(bid) {
    blockOrder = blockOrder.filter((id) => id !== bid);
    delete blocks[bid];
    if (curBlock === bid) curBlock = null;
    if (reshapeBid === bid) _clearReshape();
    closeModal();
    draw();
    updateStatus();
  }

  // ── BLOCK VIEW ───────────────────────────────────────────
  function openBlockView(bid) {
    curBlock = bid;
    curFloor = 1;
    view = "block";
    blockEditMode = false;
    canvasWrap.style.display = "none";
    plotViewEl.style.display = "block";
    $("btnEditBlock").style.display = "";
    $("btnDoneBlock").style.display = "none";
    $("pvTitle").textContent = blocks[bid].name;
    $("pvBadge").style.display = "none";

    const b = blocks[bid];
    const floorSel = $("pvFloorSelect");
    if (floorSel) {
      if ((b.floors || 1) > 1) {
        floorSel.innerHTML = Array.from({ length: b.floors }, (_, i) => i + 1)
          .map((f) => `<option value="${f}">Floor ${f}</option>`)
          .join("");
        floorSel.value = "1";
        floorSel.style.display = "";
        floorSel.onchange = () => switchFloor(parseInt(floorSel.value));
      } else {
        floorSel.style.display = "none";
      }
    }
    const schemeBtn = $("btnScheme");
    if (schemeBtn) schemeBtn.style.display = "";

    renderPlotGrid();
    updateStatus();
  }

  function switchFloor(floorNum) {
    curFloor = floorNum;
    const sel = $("pvFloorSelect");
    if (sel) sel.value = String(floorNum);
    renderPlotGrid();
  }

  function backToMap() {
    view = "map";
    curBlock = null;
    blockEditMode = false;
    canvasWrap.style.display = "block";
    plotViewEl.style.display = "none";
    $("mapBreadcrumb").innerHTML = "";
    if (mapEditMode) $("btnAddBlock").style.display = "";
    syncCanvasSize();
    draw();
    updateStatus();
  }

  function toggleEditBlock() {
    blockEditMode = !blockEditMode;
    $("btnEditBlock").style.display = blockEditMode ? "none" : "";
    $("btnDoneBlock").style.display = blockEditMode ? "" : "none";
    $("pvBadge").style.display = blockEditMode ? "" : "none";
    renderPlotGrid();
  }

  // ── PLOT GRID ────────────────────────────────────────────
  function renderPlotGrid() {
    const b = blocks[curBlock],
      pg = $("plotGrid");
    pg.style.gridTemplateColumns = `repeat(${b.cols}, 72px)`;
    pg.innerHTML = "";
    for (let r = 0; r < b.rows; r++) {
      for (let c = 0; c < b.cols; c++) {
        const key = plotKey(curFloor, r, c);
        const label = getPlotLabel(b, curFloor, r, c);
        const data = (b.plots || {})[key];
        const el = document.createElement("div");
        el.className =
          "plotCell" +
          (data ? " " + data.status : "") +
          (blockEditMode || data ? " clickable" : "");
        el.dataset.key = key;
        el.innerHTML =
          `<span class="plotCellLabel">${label}</span>` +
          (data && data.name
            ? `<span class="plotCellSub">${escHtml(data.name.split(" ")[0])}</span>`
            : "");
        if (blockEditMode) el.onclick = () => openPlotEditModal(key, label);
        else if (data) el.onclick = () => openPlotViewModal(key, label, data);
        pg.appendChild(el);
      }
    }
  }

  function openPlotEditModal(key, label) {
    curPlot = key;
    const b = blocks[curBlock],
      data = (b.plots || {})[key] || {};
    $("modalTitle").textContent = `${escHtml(b.name)} \u2014 Plot ${label}`;
    $("modalBody").innerHTML = `
      <label>Name of deceased</label>
      <input type="text" id="fPn" value="${escHtml(data.name || "")}" placeholder="e.g. Juan dela Cruz">
      <label>Years (birth &ndash; death)</label>
      <input type="text" id="fPy" value="${escHtml(data.years || "")}" placeholder="e.g. 1940&ndash;2010">
      <label>Status</label>
      <select id="fPs">
        <option value="occupied"  ${data.status === "occupied" ? "selected" : ""}>Occupied</option>
        <option value="reserved"  ${data.status === "reserved" ? "selected" : ""}>Reserved</option>
        <option value="available" ${!data.status || data.status === "available" ? "selected" : ""}>Available (clear)</option>
      </select>
      <label>Notes (optional)</label>
      <input type="text" id="fPnt" value="${escHtml(data.notes || "")}" placeholder="e.g. Family lot...">`;
    $("modalConfirm").textContent = "Save";
    $("modalConfirm").className = "btnPrimary";
    $("modalConfirm").style.display = "";
    $("modalConfirm").onclick = savePlot;
    $("modalCancel").textContent = "Cancel";
    $("modalCancel").onclick = closeModal;
    showModal();
    setTimeout(() => $("fPn") && $("fPn").focus(), 60);
  }

  function openPlotViewModal(key, label, data) {
    $("modalTitle").textContent = `Plot ${label}`;
    $("modalBody").innerHTML = `
      <div class="viewInfoBox">
        <p><strong>${escHtml(data.name || "\u2014")}</strong></p>
        ${data.years ? `<p>${escHtml(data.years)}</p>` : ""}
        <p><span class="statusPill ${data.status}">${data.status}</span></p>
        ${data.notes ? `<p style="margin-top:6px;font-size:12px">${escHtml(data.notes)}</p>` : ""}
      </div>
      <p class="modalNote">Click "Edit block" to modify plots.</p>`;
    $("modalConfirm").style.display = "none";
    $("modalCancel").textContent = "Close";
    $("modalCancel").onclick = closeModal;
    showModal();
  }

  function savePlot() {
    const status = $("fPs").value;
    if (!blocks[curBlock].plots) blocks[curBlock].plots = {};
    if (status === "available") delete blocks[curBlock].plots[curPlot];
    else {
      blocks[curBlock].plots[curPlot] = {
        name: $("fPn").value.trim(),
        years: $("fPy").value.trim(),
        status,
        notes: $("fPnt").value.trim(),
      };
    }
    closeModal();
    renderPlotGrid();
    updateStatus();
  }

  // ── MODAL ────────────────────────────────────────────────
  function showModal() {
    $("modalOverlay").classList.add("open");
  }
  function closeModal() {
    if (!$("modalOverlay")) return;
    $("modalOverlay").classList.remove("open");
    $("modalConfirm").style.display = "";
    $("modalCancel").textContent = "Cancel";
    $("modalCancel").onclick = closeModal;
    curPlot = null;
    pendingNavHref = null;
  }

  // ── UNSAVED-CHANGES NAVIGATION GUARD ──────────────────────
  // While mapEditMode is on, leaving the page (sidebar links, tab close,
  // refresh, typed URL) prompts to save first instead of silently
  // discarding whatever hasn't been saved yet.
  function openUnsavedChangesModal(href) {
    pendingNavHref = href;
    $("modalTitle").textContent = "Unsaved changes";
    $("modalBody").innerHTML = `
      <p style="font-size:13px;color:#475569;margin-top:4px;line-height:1.5">
        You're still editing the map. Save your changes before leaving this page, or they'll be lost.
      </p>
      <p class="modalNote" style="margin-top:14px">
        <a href="#" id="discardLeaveLink">Discard changes and leave anyway</a>
      </p>`;
    $("modalConfirm").textContent = "Save & leave";
    $("modalConfirm").className = "btnPrimary";
    $("modalConfirm").style.display = "";
    $("modalConfirm").onclick = () => {
      const dest = pendingNavHref;
      persistSave();
      exitEditMap();
      pendingNavHref = null;
      closeModal();
      if (dest) window.location.href = dest;
    };
    $("modalCancel").textContent = "Stay here";
    $("modalCancel").onclick = closeModal;
    showModal();
    $("discardLeaveLink").onclick = (e) => {
      e.preventDefault();
      const dest = pendingNavHref;
      exitEditMap();
      pendingNavHref = null;
      closeModal();
      if (dest) window.location.href = dest;
    };
  }

  function initNavGuard() {
    // Intercept sidebar / in-page links while editing
    document.addEventListener("click", (e) => {
      if (!mapEditMode) return;
      const link = e.target.closest("a[href]");
      if (!link) return;
      const href = link.getAttribute("href");
      if (!href || href.startsWith("#") || href.startsWith("javascript:"))
        return;
      e.preventDefault();
      openUnsavedChangesModal(href);
    });

    // Catch tab close / refresh / typed-URL navigation
    window.addEventListener("beforeunload", (e) => {
      if (mapEditMode) {
        e.preventDefault();
        e.returnValue = "";
      }
    });
  }

  // ── SEARCH ───────────────────────────────────────────────
  function doSearch(q) {
    const box = $("searchResults");
    q = q.trim().toLowerCase();
    if (!q) {
      box.style.display = "none";
      box.innerHTML = "";
      return;
    }
    const hits = [];
    blockOrder.forEach((bid) => {
      const blk = blocks[bid];
      Object.entries(blk.plots || {}).forEach(([key, p]) => {
        if (p.name && p.name.toLowerCase().includes(q)) {
          const [floor, r, c] = key.split("_").map(Number);
          const label = getPlotLabel(blk, floor, r, c);
          hits.push({ bid, blkName: blk.name, key, label, p });
        }
      });
    });
    box.innerHTML = !hits.length
      ? '<div class="searchRow" style="color:#94a3b8">No results found.</div>'
      : hits
          .map(
            (h) => `
          <div class="searchRow" onclick="MAP.jumpTo('${h.bid}','${h.key}')">
            <span><strong>${escHtml(h.p.name)}</strong>
            <span class="searchRowSub">\u2014 ${escHtml(h.blkName)}, ${h.label}</span></span>
            <span class="statusPill ${h.p.status}">${h.p.status}</span>
          </div>`,
          )
          .join("");
    box.style.display = "block";
  }

  function jumpTo(bid, key) {
    $("mapSearchInput").value = "";
    $("searchResults").style.display = "none";
    openBlockView(bid);
    const floor = parseInt(key.split("_")[0]);
    switchFloor(floor);
    setTimeout(() => {
      const pg = $("plotGrid");
      [...pg.children].forEach((el) => {
        if (el.dataset.key === key) {
          el.style.outline = "2px solid #3b82f6";
          el.scrollIntoView({ block: "nearest" });
        }
      });
    }, 30);
  }

  // ── STATUS ───────────────────────────────────────────────
  function updateStatus() {
    if (view === "map") {
      let total = 0,
        occ = 0,
        res = 0;
      blockOrder.forEach((bid) => {
        const s = blockStats(blocks[bid]);
        total += s.total;
        occ += s.occ;
        res += s.res;
      });
      $("mapStatus").textContent =
        `${blockOrder.length} block(s) \u00b7 Total: ${total} \u00b7 Occupied: ${occ} \u00b7 Reserved: ${res} \u00b7 Available: ${total - occ - res}`;
    } else {
      const s = blockStats(blocks[curBlock]);
      $("mapStatus").textContent =
        `${escHtml(blocks[curBlock].name)}: ${s.total} plots \u00b7 Occupied: ${s.occ} \u00b7 Reserved: ${s.res} \u00b7 Available: ${s.avail}`;
    }
  }

  // ── INIT ─────────────────────────────────────────────────
  document.addEventListener("DOMContentLoaded", () => {
    canvasWrap = $("mapCanvas");
    cv = $("mapCvs");
    ctx = cv.getContext("2d");
    plotViewEl = $("plotView");
    initButtons();
    initCanvasEvents();
    initNavGuard();
    persistLoad();
  });

  // ── PUBLIC API ───────────────────────────────────────────
  return {
    enterEditMap,
    exitEditMap,
    startAdd,
    cancelAdd,
    enterReshapeMode,
    exitReshapeMode,
    setReshapeTool,
    undoReshape,
    activateResize,
    openBlockView,
    backToMap,
    toggleEditBlock,
    deleteBlock,
    doSearch,
    jumpTo,
    closeModal,
    openFloorSchemeModal,
  };
})();
