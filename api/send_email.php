<?php
// =============================================================
// Vital DX インタビューシート PDF メール送信 API
// 設置先: Xserver上の vital-dx.com ドメイン配下
// =============================================================

// CORS設定（GitHub Pagesからのリクエストを許可）
header('Access-Control-Allow-Origin: https://packy-the3rd.github.io');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=UTF-8');

// プリフライトリクエスト対応
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// POSTのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// リクエストボディ取得
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// 必須パラメータチェック
$required = ['to_email', 'to_name', 'facility_name', 'visit_date', 'summary', 'pdf_base64'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: {$field}"]);
        exit;
    }
}

$to_email      = filter_var($input['to_email'], FILTER_SANITIZE_EMAIL);
$to_name       = mb_encode_mimeheader($input['to_name'], 'UTF-8');
$facility_name = $input['facility_name'];
$visit_date    = $input['visit_date'];
$summary       = $input['summary'];
$pdf_base64    = $input['pdf_base64'];

// メールアドレスのバリデーション
if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email address']);
    exit;
}

// ─── PDF デコード ───
$pdf_data = base64_decode($pdf_base64);
if ($pdf_data === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid PDF data']);
    exit;
}

// ─── メール構築（マルチパート: テキスト本文 + PDF添付）───
$from_email = 'noreply@vital-dx.com';  // ← 必要に応じて変更
$from_name  = mb_encode_mimeheader('Vital DX インタビューシート', 'UTF-8');
$subject    = mb_encode_mimeheader(
    "【Vital DX】営業インタビューシート - {$facility_name} ({$visit_date})",
    'UTF-8'
);

$boundary   = '----=_Part_' . md5(uniqid(rand(), true));
$filename   = "VDX_Interview_{$facility_name}_{$visit_date}.pdf";
$filename_encoded = mb_encode_mimeheader($filename, 'UTF-8');

// ヘッダー
$headers = implode("\r\n", [
    "From: {$from_name} <{$from_email}>",
    "Reply-To: {$from_email}",
    "MIME-Version: 1.0",
    "Content-Type: multipart/mixed; boundary=\"{$boundary}\"",
    "X-Mailer: VitalDX-Interview-Form/1.0"
]);

// 本文テキスト
$body_text = <<<EOT
{$input['to_name']} 様

お疲れ様です。
営業インタビューシートの記録をお送りいたします。

━━━━━━━━━━━━━━━━━━━━━━━━
■ 施設名: {$facility_name}
■ 訪問日: {$visit_date}
━━━━━━━━━━━━━━━━━━━━━━━━

{$summary}

━━━━━━━━━━━━━━━━━━━━━━━━
※ PDFファイルを添付しています。
※ このメールはVital DXインタビューフォームから自動送信されています。

株式会社バイタルDX
https://vital-dx.com/
EOT;

// マルチパートメール本文組み立て
$message = "--{$boundary}\r\n";
$message .= "Content-Type: text/plain; charset=UTF-8\r\n";
$message .= "Content-Transfer-Encoding: base64\r\n\r\n";
$message .= chunk_split(base64_encode($body_text));
$message .= "\r\n--{$boundary}\r\n";
$message .= "Content-Type: application/pdf; name=\"{$filename_encoded}\"\r\n";
$message .= "Content-Disposition: attachment; filename=\"{$filename_encoded}\"\r\n";
$message .= "Content-Transfer-Encoding: base64\r\n\r\n";
$message .= chunk_split($pdf_base64);
$message .= "\r\n--{$boundary}--";

// ─── メール送信 ───
$result = mail($to_email, $subject, $message, $headers);

if ($result) {
    echo json_encode([
        'success' => true,
        'message' => "Email sent to {$to_email}"
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to send email',
        'message' => 'Server mail() function returned false'
    ]);
}
