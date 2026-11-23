<?php

declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    if (($_GET['action'] ?? '') === 'captcha') {
        jsonHeaders();
        sendCaptcha();
    }

    serveIndex();
}

jsonHeaders();

if ($method !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'Метод запроса не поддерживается.',
    ]);
}

processForm();

function serveIndex(): void
{
    $indexPath = __DIR__ . '/index.html';

    if (!is_file($indexPath) || !is_readable($indexPath)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Не удалось загрузить страницу.';
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-cache');
    readfile($indexPath);
    exit;
}

function jsonHeaders(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
}

function processForm(): void
{
    $data = [
        'action' => cleanString($_POST['action'] ?? ''),
        'subject' => cleanString($_POST['subject'] ?? ''),
        'full_name' => cleanString($_POST['full_name'] ?? ''),
        'phone' => cleanString($_POST['phone'] ?? ''),
        'email' => cleanString($_POST['email'] ?? ''),
        'message' => cleanString($_POST['message'] ?? ''),
        'captcha' => strtoupper(cleanString($_POST['captcha'] ?? '')),
        'consent' => $_POST['consent'] ?? '',
    ];

    $errors = validate($data);
    unset($_SESSION['captcha_hash']);

    if ($errors !== []) {
        respond(422, [
            'success' => false,
            'message' => 'Проверьте правильность заполнения формы.',
            'errors' => $errors,
        ]);
    }

    $recipient = environmentEmail('CONTACT_EMAIL', 'greggsoft@gmail.com');
    $sender = environmentEmail('SENDER_EMAIL', 'greggsoft@gmail.com');
    $mailSubject = sprintf('Обратная связь: %s', $data['subject']);
    $encodedSubject = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($mailSubject, 'UTF-8')
        : $mailSubject;
    $body = implode("\n", [
        'Новое сообщение с формы обратной связи',
        '',
        'Тема: ' . $data['subject'],
        'ФИО: ' . $data['full_name'],
        'Телефон: ' . $data['phone'],
        'Email: ' . $data['email'],
        '',
        'Сообщение:',
        $data['message'],
    ]);
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: Feedback Form <' . $sender . '>',
        'Reply-To: ' . $data['email'],
        'X-Mailer: PHP/' . PHP_VERSION,
    ]);

    if (!mail($recipient, $encodedSubject, $body, $headers)) {
        respond(500, [
            'success' => false,
            'message' => 'Сообщение не отправлено. Попробуйте ещё раз позже.',
        ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'Спасибо! Мы получили ваше сообщение и скоро ответим.',
    ]);
}

/**
 * @param array<string, string> $data
 * @return array<string, string>
 */
function validate(array $data): array
{
    $errors = [];
    $allowedSubjects = [
        'Общий вопрос',
        'Сотрудничество',
        'Техническая поддержка',
        'Другое',
    ];

    if ($data['action'] !== 'message') {
        $errors['subject'] = 'Некорректные данные формы.';
    }

    if (!in_array($data['subject'], $allowedSubjects, true)) {
        $errors['subject'] = 'Выберите тему обращения.';
    }

    if ($data['full_name'] === '') {
        $errors['full_name'] = 'Укажите ФИО.';
    } elseif (
        textLength($data['full_name']) > 255
        || preg_match("/^[\p{L}\s.'’-]+$/u", $data['full_name']) !== 1
    ) {
        $errors['full_name'] = 'ФИО содержит недопустимые символы.';
    }

    $phoneDigits = preg_replace('/\D+/', '', $data['phone']) ?? '';
    if ($data['phone'] === '') {
        $errors['phone'] = 'Укажите номер телефона.';
    } elseif (
        textLength($data['phone']) > 255
        || preg_match('/^[+\d\s()\-]+$/', $data['phone']) !== 1
        || strlen($phoneDigits) < 10
        || strlen($phoneDigits) > 15
    ) {
        $errors['phone'] = 'Введите корректный номер телефона.';
    }

    if ($data['email'] === '') {
        $errors['email'] = 'Укажите email.';
    } elseif (
        textLength($data['email']) > 255
        || filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false
    ) {
        $errors['email'] = 'Введите корректный email.';
    }

    if ($data['message'] === '') {
        $errors['message'] = 'Введите сообщение.';
    } elseif (textLength($data['message']) > 4096) {
        $errors['message'] = 'Сообщение не должно превышать 4096 символов.';
    }

    if ($data['consent'] !== '1') {
        $errors['consent'] = 'Необходимо согласие на обработку данных.';
    }

    $captchaHash = $_SESSION['captcha_hash'] ?? '';
    if ($data['captcha'] === '') {
        $errors['captcha'] = 'Введите код с картинки.';
    } elseif (
        !is_string($captchaHash)
        || $captchaHash === ''
        || !password_verify($data['captcha'], $captchaHash)
    ) {
        $errors['captcha'] = 'Код с картинки введён неверно.';
    }

    return $errors;
}

function sendCaptcha(): void
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';

    for ($index = 0; $index < 5; $index++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    $_SESSION['captcha_hash'] = password_hash($code, PASSWORD_DEFAULT);

    $characters = '';
    foreach (str_split($code) as $index => $character) {
        $x = 24 + ($index * 34);
        $y = random_int(37, 49);
        $rotation = random_int(-16, 16);
        $characters .= sprintf(
            '<text x="%d" y="%d" transform="rotate(%d %d %d)">%s</text>',
            $x,
            $y,
            $rotation,
            $x,
            $y,
            $character,
        );
    }

    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="190" height="64" viewBox="0 0 190 64">
  <rect width="190" height="64" rx="8" fill="#eef1f7"/>
  <path d="M4 18 C45 48 82 5 186 38" fill="none" stroke="#9aa7c0" stroke-width="2"/>
  <path d="M8 51 C52 13 116 61 184 15" fill="none" stroke="#bdc6d8" stroke-width="1.5"/>
  <g fill="#172c69" font-family="monospace" font-size="30" font-weight="700">{$characters}</g>
</svg>
SVG;

    respond(200, [
        'success' => true,
        'image' => 'data:image/svg+xml;base64,' . base64_encode($svg),
    ]);
}

function cleanString(mixed $value): string
{
    return is_string($value) ? trim($value) : '';
}

function textLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function environmentEmail(string $name, string $fallback): string
{
    $value = getenv($name);
    $email = is_string($value) && $value !== '' ? $value : $fallback;

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        respond(500, [
            'success' => false,
            'message' => sprintf('Переменная окружения %s содержит некорректный email.', $name),
        ]);
    }

    return $email;
}

/**
 * @param array<string, mixed> $payload
 */
function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
