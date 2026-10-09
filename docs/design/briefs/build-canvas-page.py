import json, re, io
p = 'canvas.json'
c = json.load(io.open(p, encoding='utf-8'))
rows = [
 ("Solicitante", [
  ("B-NewType","B1 · Pedir: tipo (desde una app)"),("B-NewProblem","B1 · Pedir: problema y +1"),("B-NewTime","B1 · Pedir: tiempo de hoy"),
  ("B-NewResult","B1 · Pedir: sistemas"),("B-NewUrgency","B1 · Pedir: urgencia"),("B-NewReview","B1 · Pedir: adjuntos y revisar"),
  ("B-NewSent","B1 · Solicitud enviada"),("B-MyRequests","B2 · Mis solicitudes"),("B-RequesterEmpty","B2 · Mis solicitudes vacío"),
  ("B-RequestDetail","B2 · Detalle de mi solicitud · celular"),("B-RequestDetailDesktop","B2 · Detalle de mi solicitud · escritorio"),
  ("B-Acceptance","B3 · Validar criterios"),("B-Rate","B3 · Calificar entrega"),("B-HoursSaved","B3 · Horas ahorradas a los 30 días"),
  ("B-Roadmap","B4 · Hoja de ruta · escritorio"),("B-RoadmapPhone","B4 · Hoja de ruta · celular")]),
 ("TI · bandeja, aprobación y prioridades", [
  ("B-Inbox","B5 · Bandeja de solicitudes"),("B-InboxPhone","B5 · Bandeja · celular"),("B-RequestIT","B6 · Solicitud con evaluación"),
  ("B-RequestActions","B6 · Acciones: rechazar, pedir info, unir, aprobar"),("B-ApprovalPhone","B7 · Aprobar proyecto · celular"),
  ("B-ApprovalDone","B7 · Aprobado + Telegram"),("B-ApprovalDesktop","B7 · Aprobaciones · escritorio"),("B-Backlog","B8 · Backlog priorizado"),
  ("B-ByArea","B9 · Vista por área"),("B-MyWeek","B10 · Mi semana"),("B-MyWeekPhone","B10 · Mi semana · celular")]),
 ("Proyectos, tiempo y costos", [
  ("B-ProjectSummary","B11 · Proyecto: resumen"),("B-Kanban","B12 · Tablero Kanban"),("B-KanbanPhone","B12 · Tablero · celular"),
  ("B-TaskDetail","B13 · Detalle de tarea"),("B-TaskPhone","B13 · Tarea con cronómetro · celular"),("B-Requirements","B14 · Requerimientos a usuarios"),
  ("B-RequirementRespond","B14 · Responder un requerimiento"),("B-ProjectCosts","B15 · Tiempo y costos"),("B-TimeEntryPhone","B15 · Cargar horas · celular"),
  ("B-ProjectReport","B16 · Informe del proyecto"),("B-AdminRates","B17 · Tarifas y aprobadores")]),
 ("Productos, documentación, valor y bóveda", [
  ("B-Products","B18 · Productos"),("B-ProductDetail","B18 · Ficha del producto"),("B-Docs","B19 · Documentación: editor"),
  ("B-DocsTemplates","B19 · Plantillas"),("B-DocsHistory","B19 · Historial de versiones"),("B-DocsRepo","B19 · Documentos del repositorio"),
  ("B-Search","B19 · Buscador"),("B-Value","B20 · Dashboard de valor"),("B-ValuePhone","B20 · Valor · celular"),
  ("B-VaultLocked","B21 · Bóveda cerrada"),("B-VaultOpen","B21 · Bóveda abierta"),("B-VaultPhone","B21 · Bóveda · celular"),
  ("B-VaultDenied","B21 · Bóveda sin permiso"),("B-States","B22 · Estados del Gestor IA")]),
]
def size(name):
    s = io.open(name + '.dc.html', encoding='utf-8').read()
    m = re.search(r'"\$preview":\{"width":(\d+),"height":(\d+)\}', s)
    return int(m.group(1)), int(m.group(2))
c['pages'] = [x for x in c['pages'] if x['id'] != 'b'] + [{"id": "b", "name": "Etapa B · Gestor IA"}]
for k in [k for k in c['boards'] if k.startswith('B-')]:
    del c['boards'][k]
c['order'] = [k for k in c['order'] if not k.startswith('B-')]
notes = {k: v for k, v in c.get('notes', {}).items() if not k.startswith('b')}
y = 0
c['boards']['B-AITabs.dc.html'] = {"x": 0, "y": -700, "w": 1192, "h": 56, "title": "Pieza · Pestañas del Gestor IA", "page": "b"}
c['order'].append('B-AITabs.dc.html')
for i, (title, items) in enumerate(rows):
    x = 0; maxh = 0
    for name, t in items:
        w, h = size(name)
        c['boards'][name + '.dc.html'] = {"x": x, "y": y, "w": w, "h": h, "title": t, "page": "b"}
        c['order'].append(name + '.dc.html')
        x += w + 80; maxh = max(maxh, h)
    notes['brow%d' % i] = {"x": 0, "y": y - 260, "text": title, "kind": "title1", "maxW": x - 80, "page": "b"}
    y += maxh + 520
c['notes'] = notes
io.open(p, 'w', encoding='utf-8').write(json.dumps(c, ensure_ascii=False, indent=1))
print(len([k for k in c['boards'] if k.startswith('B-')]), 'B boards')
