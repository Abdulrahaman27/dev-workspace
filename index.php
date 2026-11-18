<?php
session_start();
require __DIR__ . '/config.php';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function go($url = 'index.php') { header('Location: ' . $url); exit; }
function post_only() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(403); exit('Invalid request token'); }
}
function slugify($value) {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $value), '-'));
    return $slug ?: 'project-' . bin2hex(random_bytes(3));
}
function project_by_id($pdo, $id) {
    $q = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
    $q->execute([(int)$id]); return $q->fetch();
}
function upload_files($pdo, $projectId, $documentId = null) {
    global $uploadDir;
    if (empty($_FILES['media']['name'][0])) return;
    $allowed = [
        'image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp',
        'application/pdf'=>'pdf'
    ];
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $count = count($_FILES['media']['name']);
    if ($count > 10) throw new RuntimeException('Upload a maximum of 10 files at once.');
    for ($i=0; $i<$count; $i++) {
        if ($_FILES['media']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        if ($_FILES['media']['error'][$i] !== UPLOAD_ERR_OK) throw new RuntimeException('A file could not be uploaded.');
        if ($_FILES['media']['size'][$i] > 8 * 1024 * 1024) throw new RuntimeException('Each file must be 8MB or smaller.');
        $tmp = $_FILES['media']['tmp_name'][$i];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset($allowed[$mime])) throw new RuntimeException('Only JPG, PNG, GIF, WEBP and PDF files are allowed.');
        $stored = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($tmp, $uploadDir . $stored)) throw new RuntimeException('Could not save uploaded file.');
        $stmt = $pdo->prepare('INSERT INTO attachments (project_id, document_id, original_name, stored_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$projectId, $documentId ?: null, basename($_FILES['media']['name'][$i]), $stored, $mime, (int)$_FILES['media']['size'][$i]]);
    }
}
$error = $_SESSION['flash_error'] ?? '';
$success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_project') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new RuntimeException('Give your project a name.');
            $slug = slugify($name);
            $base = $slug; $n = 2;
            while ($pdo->prepare('SELECT id FROM projects WHERE slug=?')->execute([$slug]) && $pdo->query("SELECT id FROM projects WHERE slug=" . $pdo->quote($slug))->fetch()) $slug = $base . '-' . $n++;
            $stmt = $pdo->prepare('INSERT INTO projects (name,slug,description,color) VALUES (?,?,?,?)');
            $stmt->execute([$name, $slug, trim($_POST['description'] ?? ''), $_POST['color'] ?? '#55745B']);
            $_SESSION['flash_success'] = 'Project created.';
            go('index.php?project=' . $pdo->lastInsertId());
        }
        if ($action === 'save_document') {
            $id = (int)($_POST['document_id'] ?? 0);
            $projectId = (int)($_POST['project_id'] ?? 0);
            if (!project_by_id($pdo, $projectId)) throw new RuntimeException('Project not found.');
            $title = trim($_POST['title'] ?? '');
            if ($title === '') throw new RuntimeException('Document title is required.');
            $content = trim($_POST['content'] ?? '');
            $type = in_array($_POST['doc_type'] ?? '', ['Note','Development log','Research','Decision','Plan'], true) ? $_POST['doc_type'] : 'Note';
            if ($id) {
                $stmt = $pdo->prepare('UPDATE documents SET title=?,content=?,doc_type=? WHERE id=? AND project_id=?');
                $stmt->execute([$title,$content,$type,$id,$projectId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO documents (project_id,title,content,doc_type) VALUES (?,?,?,?)');
                $stmt->execute([$projectId,$title,$content,$type]); $id = (int)$pdo->lastInsertId();
            }
            upload_files($pdo, $projectId, $id);
            $_SESSION['flash_success'] = 'Document saved.';
            go('index.php?project='.$projectId.'&doc='.$id);
        }
        if ($action === 'delete_document') {
            $stmt=$pdo->prepare('DELETE FROM documents WHERE id=? AND project_id=?');
            $stmt->execute([(int)$_POST['document_id'],(int)$_POST['project_id']]);
            $_SESSION['flash_success']='Document deleted.';
            go('index.php?project='.(int)$_POST['project_id']);
        }
        if ($action === 'delete_project') {
            $id=(int)$_POST['project_id'];
            $q=$pdo->prepare('SELECT stored_name FROM attachments WHERE project_id=?'); $q->execute([$id]);
            foreach($q->fetchAll() as $a) if(is_file($uploadDir.$a['stored_name'])) @unlink($uploadDir.$a['stored_name']);
            $pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$id]);
            $_SESSION['flash_success']='Project deleted.';
            go();
        }
    } catch (Throwable $ex) {
        $_SESSION['flash_error'] = $ex->getMessage();
        go('index.php' . (!empty($_POST['project_id']) ? '?project='.(int)$_POST['project_id'] : ''));
    }
}

$projects = $pdo->query("SELECT p.*, (SELECT COUNT(*) FROM documents d WHERE d.project_id=p.id) document_count, (SELECT COUNT(*) FROM attachments a WHERE a.project_id=p.id) media_count FROM projects p ORDER BY p.updated_at DESC")->fetchAll();
$projectId = (int)($_GET['project'] ?? 0);
$project = $projectId ? project_by_id($pdo,$projectId) : null;
if ($projectId && !$project) go();
$docId = (int)($_GET['doc'] ?? 0);
$doc = null;
$docs = [];
$attachments = [];
if ($project) {
    $q=$pdo->prepare('SELECT * FROM documents WHERE project_id=? ORDER BY updated_at DESC'); $q->execute([$projectId]); $docs=$q->fetchAll();
    if ($docId) { $q=$pdo->prepare('SELECT * FROM documents WHERE id=? AND project_id=?'); $q->execute([$docId,$projectId]); $doc=$q->fetch(); }
    if ($doc) { $q=$pdo->prepare('SELECT * FROM attachments WHERE document_id=? ORDER BY created_at DESC'); $q->execute([$docId]); $attachments=$q->fetchAll(); }
}
$editMode = isset($_GET['new']) || isset($_GET['edit']);
$editing = isset($_GET['edit']) && $doc;
$search = trim($_GET['q'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $project ? e($project['name']).' · ' : '' ?>Dev Workspace</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="shell">
<aside class="sidebar">
  <a class="brand" href="index.php"><span class="brand-mark">dw</span><span>dev workspace<small>YOUR BUILD LOG</small></span></a>
  <div class="side-label">WORKSPACE <a href="#new-project" class="plus">+</a></div>
  <nav class="project-nav">
    <a class="<?= !$project?'active':'' ?>" href="index.php"><span class="nav-icon">⌂</span> Overview</a>
    <?php foreach($projects as $p): ?>
      <a class="<?= $projectId==(int)$p['id']?'active':'' ?>" href="index.php?project=<?=$p['id']?>"><span class="folder-dot" style="background:<?=e($p['color'])?>"></span><?=e($p['name'])?><span class="nav-count"><?=$p['document_count']?></span></a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-bottom"><div class="avatar">DA</div><div><strong>DevAbbas</strong><small>Personal workspace</small></div><span class="online"></span></div>
</aside>
<main class="main">
<header class="topbar"><div class="crumb">Workspace <span>/</span> <?= $project?e($project['name']):'Overview' ?></div><div class="top-actions"><span class="today"><?=date('D, j M Y')?></span><span class="avatar small">DA</span></div></header>
<div class="content">
<?php if($error): ?><div class="notice error"><?=e($error)?></div><?php endif; ?>
<?php if($success): ?><div class="notice success"><?=e($success)?></div><?php endif; ?>

<?php if(!$project): ?>
  <section class="welcome">
    <div><div class="eyebrow">YOUR PERSONAL BUILD SPACE</div><h1>Make things.<br><em>Document everything.</em></h1><p>A home for your projects, ideas, experiments and the decisions behind them.</p></div>
    <div class="welcome-art"><div class="art-card card-one">✳ <small>IDEAS</small></div><div class="art-card card-two">⌘ <small>BUILDS</small></div><div class="art-orbit"></div></div>
  </section>
  <div class="section-head"><div><div class="eyebrow">YOUR WORK</div><h2>Projects <span class="muted-count"><?=count($projects)?></span></h2></div><button class="btn primary" onclick="document.getElementById('new-project').showModal()">＋ New project</button></div>
  <div class="project-grid">
  <?php foreach($projects as $p): ?>
    <a class="project-card" href="index.php?project=<?=$p['id']?>">
      <div class="project-card-top"><span class="folder-icon" style="--folder:<?=e($p['color'])?>">▰</span><span class="more">↗</span></div>
      <h3><?=e($p['name'])?></h3><p><?=e($p['description'] ?: 'No description added yet.')?></p>
      <div class="project-meta"><span><?=$p['document_count']?> documents</span><span><?=$p['media_count']?> media files</span></div>
    </a>
  <?php endforeach; ?>
  <button class="project-card add-card" onclick="document.getElementById('new-project').showModal()"><span class="add-circle">＋</span><strong>Create a project</strong><small>Start a new workspace folder</small></button>
  </div>
  <dialog id="new-project" class="modal"><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="create_project"><div class="modal-head"><div><div class="eyebrow">NEW WORKSPACE</div><h2>Create a project</h2></div><button type="button" class="icon-btn" onclick="this.closest('dialog').close()">×</button></div><label>Project name<input name="name" placeholder="e.g. My new project" required autofocus></label><label>Short description<textarea name="description" rows="3" placeholder="What are you building?"></textarea></label><label>Folder accent<input type="color" name="color" value="#55745B"></label><button class="btn primary full">Create project <span>→</span></button></form></dialog>

<?php elseif($editMode): ?>
  <div class="backline"><a href="index.php?project=<?=$projectId?>">← Back to <?=e($project['name'])?></a></div>
  <div class="editor-wrap"><div class="eyebrow"><?= $editing?'EDIT DOCUMENT':'NEW DOCUMENT' ?> · <?=e($project['name'])?></div><h1><?= $editing?'Refine your notes.':'Capture the work.' ?></h1><p class="subtext">Ideas, progress, discoveries and decisions. Get it out of your head and into the log.</p>
  <form method="post" enctype="multipart/form-data" class="document-form">
    <input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="save_document"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="document_id" value="<?=$editing?$doc['id']:0?>">
    <label>Document title<input name="title" required placeholder="What are you working on?" value="<?=$editing?e($doc['title']):''?>"></label>
    <label>Document type<select name="doc_type"><?php foreach(['Note','Development log','Research','Decision','Plan'] as $type): ?><option <?=($editing?$doc['doc_type']:'Development log')===$type?'selected':''?>><?=$type?></option><?php endforeach; ?></select></label>
    <label>What's happening?<textarea class="writing-area" name="content" rows="16" placeholder="## What I'm working on today&#10;&#10;What did I build?&#10;What did I learn?&#10;What is blocked?&#10;What comes next?"><?=$editing?e($doc['content']):''?></textarea></label>
    <div class="upload-box"><div class="upload-icon">＋</div><div><strong>Add media or reference files</strong><small>Images, screenshots and PDFs · Up to 8MB each · 10 files per upload</small></div><input type="file" name="media[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf"></div>
    <div class="form-actions"><a class="btn ghost" href="index.php?project=<?=$projectId?>">Cancel</a><button class="btn primary">Save document <span>→</span></button></div>
  </form></div>

<?php else: ?>
  <div class="backline"><a href="index.php">← All projects</a></div>
  <section class="project-hero"><div><div class="eyebrow">PROJECT FOLDER</div><h1><?=e($project['name'])?><span class="period">.</span></h1><p><?=e($project['description'])?></p><div class="hero-stats"><span><b><?=count($docs)?></b> documents</span><span><b><?=array_sum(array_column($docs,'id'))>=0 ? (int)$pdo->query("SELECT COUNT(*) FROM attachments WHERE project_id=".$projectId)->fetchColumn() : 0?></b> media files</span><span>Updated <?=date('j M Y',strtotime($project['updated_at']))?></span></div></div><div class="hero-folder" style="--folder:<?=e($project['color'])?>">▰</div></section>
  <div class="section-head doc-head"><div><div class="eyebrow">PROJECT CONTENTS</div><h2>Documents <span class="muted-count"><?=count($docs)?></span></h2></div><a class="btn primary" href="index.php?project=<?=$projectId?>&new=1">＋ New document</a></div>
  <div class="doc-layout">
    <section class="doc-list">
      <form class="searchbar" method="get"><input type="hidden" name="project" value="<?=$projectId?>"><span>⌕</span><input name="q" value="<?=e($search)?>" placeholder="Search project documents..."></form>
      <?php $shown=0; foreach($docs as $d): if($search && stripos($d['title'].' '.$d['content'],$search)===false) continue; $shown++; ?>
      <a class="doc-row <?= $docId==(int)$d['id']?'selected':'' ?>" href="index.php?project=<?=$projectId?>&doc=<?=$d['id']?>">
        <span class="doc-icon"><?= $d['doc_type']==='Development log'?'↗':($d['doc_type']==='Research'?'⌕':'▤') ?></span><span class="doc-info"><strong><?=e($d['title'])?></strong><small><?=e($d['doc_type'])?> · <?=date('j M Y',strtotime($d['updated_at']))?></small></span><span class="row-arrow">→</span>
      </a>
      <?php endforeach; if(!$shown): ?><div class="empty-state"><div>▤</div><strong>No documents yet</strong><p>Create your first note or development log.</p><a class="btn primary" href="index.php?project=<?=$projectId?>&new=1">Create document</a></div><?php endif; ?>
    </section>
    <aside class="doc-preview">
      <?php if($doc): ?>
        <div class="preview-top"><span class="type-pill"><?=e($doc['doc_type'])?></span><div class="preview-actions"><a href="index.php?project=<?=$projectId?>&edit=1&doc=<?=$doc['id']?>">Edit ↗</a></div></div>
        <h2><?=e($doc['title'])?></h2><div class="preview-date">Updated <?=date('j F Y · g:i a',strtotime($doc['updated_at']))?></div>
        <div class="preview-content"><?=nl2br(e($doc['content']))?></div>
        <?php if($attachments): ?><div class="attachment-section"><h4>ATTACHMENTS · <?=count($attachments)?></h4><div class="attachment-grid"><?php foreach($attachments as $a): ?><a class="attachment" href="<?=e($uploadUrl.$a['stored_name'])?>" target="_blank"><span><?=str_starts_with($a['mime_type'],'image/')?'▧':'PDF'?></span><strong><?=e($a['original_name'])?></strong><small><?=round($a['file_size']/1024)?> KB</small></a><?php endforeach; ?></div></div><?php endif; ?>
        <form method="post" onsubmit="return confirm('Delete this document? This cannot be undone.')"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="delete_document"><input type="hidden" name="project_id" value="<?=$projectId?>"><input type="hidden" name="document_id" value="<?=$doc['id']?>"><button class="text-danger">Delete document</button></form>
      <?php else: ?><div class="preview-empty"><div class="preview-illustration">✳</div><h3>Your project, in progress.</h3><p>Select a document to read it, or create a new one to capture what you're building.</p><a class="btn primary" href="index.php?project=<?=$projectId?>&new=1">＋ Write your first document</a></div><?php endif; ?>
    </aside>
  </div>
  <div class="project-danger"><details><summary>Project settings</summary><p>Deleting this project permanently deletes its documents and attachments.</p><form method="post" onsubmit="return confirm('Permanently delete this project and all its documents?')"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?=$projectId?>"><button class="text-danger">Delete project</button></form></details></div>
<?php endif; ?>
<footer>Built to make the work visible. <span>DEV WORKSPACE · <?=date('Y')?></span></footer>
</div></main></div>
</body></html>
