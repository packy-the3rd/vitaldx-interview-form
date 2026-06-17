<?php
// =============================================================
// Vital DX インタビューシート PDF メール送信 API
// Gmail SMTP 経由（PHPMailer使用）
// 設置先: vital-dx.com/amenity-forms/api/send_email.php
// =============================================================

// PHPMailer ライブラリ読み込み
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =============================================================
// Gmail SMTP 設定
// 
// 【アプリパスワードの取得方法】
// 1. Googleアカウント → セキュリティ → 2段階認証を有効化
// 2. セキュリティ → アプリパスワード → 「メール」を選択
// 3. 生成された16文字のパスワードを下記に設定
// =============================================================
$GMAIL_ADDRESS  = 'your-email@gmail.com';    // ← Gmailアドレスを設定
$GMAIL_APP_PASS = 'xxxx xxxx xxxx xxxx';     // ← アプリパスワードを設定
$FROM_NAME      = 'Vital DX インタビューシート';

// =============================================================

header('Content-Type: application/json; charset=UTF-8');

// POSTのみ許可
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // 同一ドメインなのでCORSは基本不要だが念のため
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit;
}

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
        echo json_encode(['error' => "Missing: {$field}"]);
        exit;
    }
}

$to_email      = filter_var($input['to_email'], FILTER_SANITIZE_EMAIL);
$to_name       = $input['to_name'];
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

// PDF デコード
$pdf_data = base64_decode($pdf_base64);
if ($pdf_data === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid PDF data']);
    exit;
}

// ─── PHPMailer でメール送信 ───
$mail = new PHPMailer(true);

try {
    // Gmail SMTP 設定
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $GMAIL_ADDRESS;
    $mail->Password   = $GMAIL_APP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->CharSet    = 'UTF-8';
    $mail->Encoding   = 'base64';

    // 送信元・送信先
    $mail->setFrom($GMAIL_ADDRESS, $FROM_NAME);
    $mail->addAddress($to_email, $to_name);
    // BCCで社内にもコピー（任意）
    // $mail->addBCC('sales@vital-dx.com', 'VitalDX Sales');

    // 件名
    $mail->Subject = "【Vital DX】営業インタビューシート - {$facility_name} ({$visit_date})";

    // 本文
    $mail->isHTML(false);
    $mail->Body = <<<EOT
{$to_name} 様

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

    // PDF添付
    $filename = "VDX_Interview_{$facility_name}_{$visit_date}.pdf";
    $mail->addStringAttachment($pdf_data, $filename, 'base64', 'application/pdf');

    // 送信
    $mail->send();

    echo json_encode([
        'success' => true,
        'message' => "Email sent to {$to_email}"
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'Mail send failed',
        'message' => $mail->ErrorInfo
    ]);
}
