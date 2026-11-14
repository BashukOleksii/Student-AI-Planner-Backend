<!DOCTYPE html>
<html lang="uk">
<head><meta charset="utf-8"><title>Навчальне нагадування</title></head>
<body>
    <h1>Навчальне нагадування</h1>
    <p style="white-space: pre-wrap">{{ $reminderMessage }}</p>
    @if ($inboxAvailable)
        <p>Це нагадування також доступне у «Вхідних» застосунку.</p>
    @endif
    <p>Вимкнути поштові нагадування можна в налаштуваннях профілю.</p>
</body>
</html>
