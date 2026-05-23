const MAP = (() => {
  // ── CONSTANTS ────────────────────────────────────────────
  const CELL = 22;
  const STORAGE_KEY = "cemetery-map-v1";

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
  let hoveredBlock = null;
  let drag = { active: false, sx: 0, sy: 0, ex: 0, ey: 0 };
  let blocks = {};
  let blockOrder = [];

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
    const total = b.rows * b.cols;
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
        blockOrder.forEach((bid) => ensurePolygon(blocks[bid]));
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
      const { occ, res, avail } = blockStats(b);
      const bounds = polyBounds(pts);
      const cx = bounds.x + bounds.w / 2;
      const cy = bounds.y + bounds.h / 2;

      ctx.globalAlpha = reshapeMode && !isResh ? 0.25 : isRes ? 0.4 : 1;

      ctx.beginPath();
      pts.forEach((p, i) =>
        i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y),
      );
      ctx.closePath();

      ctx.fillStyle = isResh ? "#fef9c3" : "#dbeafe";
      ctx.strokeStyle = isResh
        ? "#f59e0b"
        : isRes
          ? "#ef4444"
          : hov
            ? "#1d4ed8"
            : "#3b82f6";
      ctx.lineWidth = isResh || hov || isRes ? 2 : 1;
      ctx.fill();
      ctx.stroke();

      ctx.fillStyle = "#1e3a5f";
      ctx.font = "500 13px Inter, sans-serif";
      ctx.textAlign = "center";
      ctx.fillText(b.name, cx, cy - 14);

      ctx.fillStyle = "#2563eb";
      ctx.font = "10px Inter, sans-serif";
      ctx.fillText(`${b.rows}\u00d7${b.cols} plots`, cx, cy + 2);
      ctx.fillText(
        `${occ} occ \u00b7 ${res} res \u00b7 ${avail} avail`,
        cx,
        cy + 16,
      );

      if (!reshapeMode) {
        ctx.fillStyle = isRes ? "#ef4444" : mapEditMode ? "#3b82f6" : "#2563eb";
        ctx.fillText(
          isRes
            ? "drag to resize\u2026"
            : mapEditMode
              ? "click to edit"
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
      if (drag.active || (reshapeMode && drawPoints.length > 0)) {
        if (e.cancelable) e.preventDefault();
      }

      const { x, y } = getXY(e);
      mousePos = { x, y };

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
        ? "pointer"
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
      mapEditMode ? openBlockEditModal(bid) : openBlockView(bid);
    };

    const handleUp = (e) => {
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
      if (drag.active) handleUp(e);
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
    setHint('Click "Add block" to draw a block, or click a block to edit it.');
    draw();
  }

  function exitEditMap() {
    if (reshapeMode) _clearReshape();
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
      <p style="font-size:11px;color:#94a3b8;margin-top:8px">Drawn area: ${bw}&times;${bh}px &mdash; adjust divisions above.</p>`;

    $("modalConfirm").textContent = "Save";
    $("modalConfirm").className = "btnPrimary";
    $("modalConfirm").style.display = "";
    $("modalConfirm").onclick = () => {
      const name = $("fBn").value.trim();
      const c = parseInt($("fBc").value) || cols;
      const r = parseInt($("fBr").value) || rows;
      if (!name) {
        $("fBn").focus();
        return;
      }
      const bid = uid();
      blocks[bid] = {
        name,
        x: bx,
        y: by,
        w: bw,
        h: bh,
        rows: r,
        cols: c,
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
      if (!name) return;
      blocks[bid].name = name;
      blocks[bid].cols = cols;
      blocks[bid].rows = rows;
      closeModal();
      draw();
      updateStatus();
    };
    $("modalCancel").textContent = "Cancel";
    $("modalCancel").onclick = closeModal;
    showModal();
    setTimeout(() => $("fEn") && $("fEn").focus(), 60);
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
    view = "block";
    blockEditMode = false;
    canvasWrap.style.display = "none";
    plotViewEl.style.display = "block";
    $("btnEditBlock").style.display = "";
    $("btnDoneBlock").style.display = "none";
    $("pvTitle").textContent = blocks[bid].name;
    $("pvBadge").style.display = "none";
    renderPlotGrid();
    updateStatus();
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
        const pid = `R${r + 1}-C${c + 1}`;
        const data = (b.plots || {})[pid];
        const el = document.createElement("div");
        el.className =
          "plotCell" +
          (data ? " " + data.status : "") +
          (blockEditMode || data ? " clickable" : "");
        el.innerHTML =
          `<span class="plotCellLabel">${pid}</span>` +
          (data && data.name
            ? `<span class="plotCellSub">${escHtml(data.name.split(" ")[0])}</span>`
            : "");
        if (blockEditMode) el.onclick = () => openPlotEditModal(pid);
        else if (data) el.onclick = () => openPlotViewModal(pid, data);
        pg.appendChild(el);
      }
    }
  }

  function openPlotEditModal(pid) {
    curPlot = pid;
    const b = blocks[curBlock],
      data = (b.plots || {})[pid] || {};
    $("modalTitle").textContent = `${escHtml(b.name)} \u2014 Plot ${pid}`;
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

  function openPlotViewModal(pid, data) {
    $("modalTitle").textContent = `Plot ${pid}`;
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
      Object.entries(blk.plots || {}).forEach(([pid, p]) => {
        if (p.name && p.name.toLowerCase().includes(q))
          hits.push({ bid, blkName: blk.name, pid, p });
      });
    });
    box.innerHTML = !hits.length
      ? '<div class="searchRow" style="color:#94a3b8">No results found.</div>'
      : hits
          .map(
            (h) => `
          <div class="searchRow" onclick="MAP.jumpTo('${h.bid}','${h.pid}')">
            <span><strong>${escHtml(h.p.name)}</strong>
            <span class="searchRowSub">\u2014 ${escHtml(h.blkName)}, ${h.pid}</span></span>
            <span class="statusPill ${h.p.status}">${h.p.status}</span>
          </div>`,
          )
          .join("");
    box.style.display = "block";
  }

  function jumpTo(bid, pid) {
    $("mapSearchInput").value = "";
    $("searchResults").style.display = "none";
    openBlockView(bid);
    setTimeout(() => {
      const b = blocks[bid],
        pg = $("plotGrid");
      [...pg.children].forEach((el, i) => {
        const r = Math.floor(i / b.cols),
          c = i % b.cols;
        if (`R${r + 1}-C${c + 1}` === pid) {
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
  };
})();
