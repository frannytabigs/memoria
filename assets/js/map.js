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
  let reshapeBid = null; // block currently being reshaped
  let reshapeTool = "cut"; // 'cut' | 'draw'
  let reshapeHistory = []; // undo stack: array of points arrays
  let cutFirstPoint = null; // first edge-click for Cut Line tool
  let drawPoints = []; // accumulated clicks for Draw Line tool
  let mousePos = { x: 0, y: 0 }; // live mouse position for preview line
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

  // Project point onto segment, return { point, t } where t ∈ [0,1]
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

  // Find the closest edge of a polygon to a point; returns { edgeIndex, point, dist }
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

  // Insert a point on a polygon edge at edgeIndex (after index i, before i+1)
  function insertPointOnEdge(pts, edgeIndex, point) {
    const out = [...pts];
    out.splice(edgeIndex + 1, 0, { ...point });
    return out;
  }

  // Find closest existing vertex to (mx,my) within radius
  function closestVertex(pts, mx, my, radius) {
    let best = null,
      bestDist = radius;
    pts.forEach((p, i) => {
      const d = dist({ x: mx, y: my }, p);
      if (d < bestDist) {
        bestDist = d;
        best = i;
      }
    });
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

  // ── CANVAS SIZE ──────────────────────────────────────────
  function syncCanvasSize() {
    const w = canvasWrap.clientWidth,
      h = canvasWrap.clientHeight;
    if (cv.width !== w || cv.height !== h) {
      cv.width = w;
      cv.height = h;
    }
  }

  // ── PERSISTENCE (manual save only) ───────────────────────
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
    // No save indicator element needed — just a console note; add one to HTML if desired
  }

  // ── DRAW ─────────────────────────────────────────────────
  function draw() {
    syncCanvasSize();
    ctx.clearRect(0, 0, cv.width, cv.height);
    if (view !== "map") return;

    if (!blockOrder.length) {
      ctx.fillStyle = "#94a3b8";
      ctx.font = "13px Inter, sans-serif";
      ctx.textAlign = "center";
      ctx.fillText(
        mapEditMode
          ? 'Click "Add block" then drag to draw a block.'
          : 'No blocks yet. Click "Edit map" to get started.',
        cv.width / 2,
        cv.height / 2,
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

      // Fill + stroke polygon
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

      // Labels
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

      // ── RESHAPE OVERLAYS ──
      if (isResh) {
        // Draw edge dots + vertex dots
        pts.forEach((p, i) => {
          const next = pts[(i + 1) % pts.length];
          // Edge midpoint indicator
          const mx2 = (p.x + next.x) / 2,
            my2 = (p.y + next.y) / 2;
          ctx.beginPath();
          ctx.arc(mx2, my2, 3, 0, Math.PI * 2);
          ctx.fillStyle = "rgba(99,102,241,0.5)";
          ctx.fill();
        });

        // Vertex handles
        pts.forEach((p, i) => {
          ctx.beginPath();
          ctx.arc(p.x, p.y, 5, 0, Math.PI * 2);
          ctx.fillStyle = "#6366f1";
          ctx.strokeStyle = "#fff";
          ctx.lineWidth = 2;
          ctx.fill();
          ctx.stroke();
        });

        // Cut tool: highlight nearest edge + first point already picked
        if (reshapeTool === "cut") {
          // First cut point indicator
          if (cutFirstPoint) {
            ctx.beginPath();
            ctx.arc(cutFirstPoint.x, cutFirstPoint.y, 7, 0, Math.PI * 2);
            ctx.fillStyle = "#ef4444";
            ctx.strokeStyle = "#fff";
            ctx.lineWidth = 2;
            ctx.fill();
            ctx.stroke();
            // Preview line to mouse
            ctx.beginPath();
            ctx.moveTo(cutFirstPoint.x, cutFirstPoint.y);
            ctx.lineTo(mousePos.x, mousePos.y);
            ctx.strokeStyle = "#ef4444";
            ctx.lineWidth = 1.5;
            ctx.setLineDash([4, 3]);
            ctx.stroke();
            ctx.setLineDash([]);
          }
          // Hover: show closest edge point
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

        // Draw tool: show placed points + preview line
        if (reshapeTool === "draw" && drawPoints.length > 0) {
          // Draw placed points
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

          // Show snap-to-edge indicator for first and last point
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

          // First placed point highlight (to indicate where to close)
          ctx.beginPath();
          ctx.arc(drawPoints[0].x, drawPoints[0].y, 7, 0, Math.PI * 2);
          ctx.fillStyle = "#16a34a";
          ctx.strokeStyle = "#fff";
          ctx.lineWidth = 2;
          ctx.fill();
          ctx.stroke();
        }

        // Draw tool with no points: show edge snap indicator
        if (reshapeTool === "draw" && drawPoints.length === 0) {
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

    // Drag preview
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
    const r = cv.getBoundingClientRect(),
      s = e.touches ? e.touches[0] : e;
    return { x: s.clientX - r.left, y: s.clientY - r.top };
  }

  // ── RESHAPE LOGIC ────────────────────────────────────────

  /**
   * CUT LINE tool:
   * User clicks two points on polygon edges. The polygon is "cut" along that
   * line — the smaller region (the cut-off chunk) is removed, leaving an
   * arbitrary new shape. Works for any convex or concave polygon.
   *
   * Algorithm:
   * 1. Click 1: find nearest edge, insert point P1 on that edge → store
   * 2. Click 2: find nearest edge, insert point P2 on that edge → cut
   * 3. Build two sub-polygons from P1→P2 going each way around; keep the larger one.
   */
  function handleCutClick(mx, my) {
    const pts = ensurePolygon(blocks[reshapeBid]);
    const edge = closestEdge(pts, mx, my);
    if (!edge || edge.dist > 24) return;

    if (!cutFirstPoint) {
      // Store first point — insert it into polygon temporarily for reference
      cutFirstPoint = { ...edge.point, edgeIndex: edge.edgeIndex };
      draw();
      return;
    }

    // We have both points — perform the cut
    const p1 = cutFirstPoint;
    const p2 = { ...edge.point, edgeIndex: edge.edgeIndex };

    // Reset first point
    cutFirstPoint = null;

    // Insert both cut-points into the polygon (higher index first to avoid shift)
    let workPts = [...pts];

    // Insert p2 first (if its edge index >= p1's edge index, to avoid index shifting)
    let idx1 = p1.edgeIndex,
      idx2 = p2.edgeIndex;
    let pt1 = { x: p1.x, y: p1.y },
      pt2 = { x: p2.x, y: p2.y };

    if (idx2 < idx1 || idx2 === idx1) {
      // Swap so we always insert higher index first
      [idx1, idx2] = [idx2, idx1];
      [pt1, pt2] = [pt2, pt1];
    }

    // Insert pt2 at idx2+1
    workPts.splice(idx2 + 1, 0, { ...pt2 });
    // Insert pt1 at idx1+1 (idx2+1 shifted everything >= idx2+1 by 1, idx1 < idx2 so unaffected)
    workPts.splice(idx1 + 1, 0, { ...pt1 });

    // Now find the indices of the two inserted points in workPts
    const i1 = idx1 + 1;
    const i2 = idx2 + 2; // +2 because we inserted at idx1+1 before it

    // Build two polygons by going each way between i1 and i2
    const polyA = [],
      polyB = [];
    // polyA: from i1 → i2 going forward
    for (let i = i1; ; i = (i + 1) % workPts.length) {
      polyA.push(workPts[i]);
      if (i === i2) break;
    }
    // polyB: from i2 → i1 going forward
    for (let i = i2; ; i = (i + 1) % workPts.length) {
      polyB.push(workPts[i]);
      if (i === i1) break;
    }

    // Keep the larger polygon (by bounding-box area)
    const areaOf = (arr) => {
      const b = polyBounds(arr);
      return b.w * b.h;
    };
    const kept = areaOf(polyA) >= areaOf(polyB) ? polyA : polyB;

    // Validate: must have at least 3 points
    if (kept.length < 3) {
      draw();
      return;
    }

    // Push history and apply
    reshapeHistory.push([...pts]);
    blocks[reshapeBid].points = kept;
    draw();
  }

  /**
   * DRAW LINE tool:
   * User clicks on the polygon border to start, places intermediate points freely
   * (snapping to the grid), and clicks on the border again to finish.
   * The new path replaces the original border segment between the two border points,
   * effectively "indenting" or "extruding" that section.
   *
   * Click 1 on border: start point (snapped to nearest edge)
   * Clicks 2..N anywhere: intermediate waypoints
   * Final click on border (within 20px): close the cut, apply.
   */
  function handleDrawClick(mx, my) {
    const pts = ensurePolygon(blocks[reshapeBid]);
    const edge = closestEdge(pts, mx, my);
    const onEdge = edge && edge.dist < 24;

    if (drawPoints.length === 0) {
      // Must start on the polygon edge
      if (!onEdge) return;
      drawPoints = [{ ...edge.point, edgeIndex: edge.edgeIndex, onEdge: true }];
      draw();
      return;
    }

    // Check if closing: final click near the polygon edge (and not near start)
    if (onEdge && drawPoints.length >= 1) {
      const startPt = drawPoints[0];
      // Avoid closing immediately on same spot
      if (dist(edge.point, startPt) < 10 && drawPoints.length < 2) return;

      const endPt = { ...edge.point, edgeIndex: edge.edgeIndex };

      // Build the replacement: insert the drawn path between the two edge-snap points
      let workPts = [...pts];
      let idx1 = startPt.edgeIndex,
        idx2 = endPt.edgeIndex;
      let pt1 = { x: startPt.x, y: startPt.y };
      let pt2 = { x: endPt.x, y: endPt.y };
      const midPoints = drawPoints.slice(1).map((p) => ({ x: p.x, y: p.y }));

      // Insert in correct order (higher index first to avoid shift)
      if (idx2 < idx1) {
        [idx1, idx2] = [idx2, idx1];
        [pt1, pt2] = [pt2, pt1];
        midPoints.reverse();
      }

      workPts.splice(idx2 + 1, 0, { ...pt2 });
      workPts.splice(idx1 + 1, 0, { ...pt1 });

      const i1 = idx1 + 1;
      const i2 = idx2 + 2;

      // Replace the short arc (i1 → i2) with the drawn path
      // Build the new polygon: keep far arc, replace near arc with drawn points
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

      // polyA is the short arc we're replacing; polyB is the long arc we're keeping
      // New polygon = polyB + midPoints in between
      const newPoly = [
        pt1,
        ...midPoints,
        pt2,
        ...polyB.slice(1, polyB.length - 1), // exclude pt2 and pt1 which are already added
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

    // Intermediate waypoint — snap to grid
    drawPoints.push({ x: snap(mx), y: snap(my), onEdge: false });
    draw();
  }

  // ── CANVAS EVENTS ────────────────────────────────────────
  function initCanvasEvents() {
    cv.addEventListener("mousemove", (e) => {
      if (view !== "map") return;
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
      } // redraws preview

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
    });

    cv.addEventListener("mousedown", (e) => {
      if (view !== "map") return;
      const { x, y } = getXY(e);

      if (reshapeMode) {
        if (!reshapeBid) return;
        if (reshapeTool === "cut") {
          handleCutClick(x, y);
          return;
        }
        if (reshapeTool === "draw") {
          handleDrawClick(x, y);
          return;
        }
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
    });

    cv.addEventListener("mouseup", (e) => {
      if (!drag.active) return;
      const { x, y } = getXY(e);
      drag.ex = snap(x);
      drag.ey = snap(y);
      const rx = Math.min(drag.sx, drag.ex),
        ry = Math.min(drag.sy, drag.ey);
      const rw = Math.max(snap(Math.abs(drag.ex - drag.sx)), CELL * 2);
      const rh = Math.max(snap(Math.abs(drag.ey - drag.sy)), CELL * 2);
      drag.active = false;
      if (resizeMode && resizingBid) finishResize(rx, ry, rw, rh);
      else if (addMode && rw >= CELL * 2 && rh >= CELL * 2)
        openNewBlockModal(
          rx,
          ry,
          rw,
          rh,
          Math.max(1, Math.round(rh / CELL)),
          Math.max(1, Math.round(rw / CELL)),
        );
      else draw();
    });

    cv.addEventListener("dblclick", (e) => {
      // Double-click in Draw mode = finish the drawn line early (treat as edge click)
      if (!reshapeMode || reshapeTool !== "draw" || drawPoints.length < 2)
        return;
      const { x, y } = getXY(e);
      const pts = ensurePolygon(blocks[reshapeBid]);
      const edge = closestEdge(pts, x, y);
      if (edge && edge.dist < 30) handleDrawClick(x, y);
    });

    window.addEventListener("mouseup", () => {
      if (drag.active) {
        drag.active = false;
        draw();
      }
    });
    window.addEventListener("resize", () => {
      syncCanvasSize();
      draw();
    });

    // Escape key: cancel current reshape action without losing progress
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
    $("mapHint").textContent = t;
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
    // Show reshape toolbar, hide normal toolbar items
    $("btnAddBlock").style.display = "none";
    $("btnCancelAdd").style.display = "none";
    $("btnCutLine").style.display = "";
    $("btnDrawLine").style.display = "";
    $("btnDoneReshape").style.display = "";
    $("btnUndoReshape").style.display = "";
    _highlightReshapeTool();
    canvasWrap.style.cursor = "crosshair";
    closeModal();
    setHint(
      "CUT LINE: click two points on edges to cut away a section. DRAW LINE: click edge → draw points → click edge to add a protrusion.",
    );
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
        "CUT LINE: click a point on any edge (first cut), then click a second edge point to slice off that section.",
      );
    if (tool === "draw")
      setHint(
        "DRAW LINE: click on an edge to start, click anywhere to add waypoints, then click another edge to finish the new shape.",
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
      <p style="font-size:11px;color:#94a3b8;margin-top:8px">Drawn area: ${bw}&times;${bh}px &mdash; adjust plot divisions above.</p>`;
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
      <input type="text" id="fPnt" value="${escHtml(data.notes || "")}" placeholder="e.g. Family lot, prepaid&hellip;">`;
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
    else
      blocks[curBlock].plots[curPlot] = {
        name: $("fPn").value.trim(),
        years: $("fPy").value.trim(),
        status,
        notes: $("fPnt").value.trim(),
      };
    closeModal();
    renderPlotGrid();
    updateStatus();
  }

  // ── MODAL ────────────────────────────────────────────────
  function showModal() {
    $("modalOverlay").classList.add("open");
  }
  function closeModal() {
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
