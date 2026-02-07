<?php
use Psr\Http\Message\ResponseInterface;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use Slim\Factory\AppFactory;
use DI\Container;

require 'vendor/autoload.php';

$_SERVER += ['PATH_INFO' => $_SERVER['REQUEST_URI']];
$_SERVER['SCRIPT_NAME'] = '/' . basename($_SERVER['SCRIPT_FILENAME']);
$file = dirname(__DIR__) . '/public' . $_SERVER['REQUEST_URI'];
if (is_file($file)) {
    if (PHP_SAPI == 'cli-server') return false;
    $mimetype = [
        'js' => 'application/javascript',
        'css' => 'text/css',
        'ico' => 'image/vnd.microsoft.icon',
    ][pathinfo($file, PATHINFO_EXTENSION)] ?? false;
    if ($mimetype) {
        header("Content-Type: {$mimetype}");
        echo file_get_contents($file); exit;
    }
}

const POSTS_PER_PAGE = 20;
const UPLOAD_LIMIT = 10 * 1024 * 1024;
const IMAGE_DIR = '/home/public/image';

function get_image_ext($mime) {
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        default => ''
    };
}

function get_image_path($id, $mime) {
    $ext = get_image_ext($mime);
    return IMAGE_DIR . "/{$id}.{$ext}";
}

// memcached session
$memd_addr = '127.0.0.1:11211';
if (isset($_SERVER['ISUCONP_MEMCACHED_ADDRESS'])) {
    $memd_addr = $_SERVER['ISUCONP_MEMCACHED_ADDRESS'];
}
ini_set('session.save_handler', 'memcached');
ini_set('session.save_path', $memd_addr);

session_start();

// dependency
$container = new Container();
$container->set('settings', function() {
    return [
        'public_folder' => dirname(dirname(__FILE__)) . '/public',
        'db' => [
            'host' => $_SERVER['ISUCONP_DB_HOST'] ?? 'localhost',
            'port' => $_SERVER['ISUCONP_DB_PORT'] ?? 3306,
            'username' => $_SERVER['ISUCONP_DB_USER'] ?? 'root',
            'password' => $_SERVER['ISUCONP_DB_PASSWORD'] ?? null,
            'database' => $_SERVER['ISUCONP_DB_NAME'] ?? 'isuconp',
        ],
    ];
});
$container->set('db', function ($c) {
    $config = $c->get('settings');
    return new PDO(
        "mysql:dbname={$config['db']['database']};host={$config['db']['host']};port={$config['db']['port']};charset=utf8mb4",
        $config['db']['username'],
        $config['db']['password']
    );
});

$container->set('view', function ($c) {
    return new class(__DIR__ . '/views/') extends \Slim\Views\PhpRenderer {
        public function render(\Psr\Http\Message\ResponseInterface $response, string $template, array $data = []): ResponseInterface {
            $data += ['view' => $template];
            return parent::render($response, 'layout.php', $data);
        }
    };
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages;
});

$container->set('helper', function ($c) {
    return new class($c) {
        public PDO $db;

        public function __construct($c) {
            $this->db = $c->get('db');
        }

        public function db() {
            return $this->db;
        }

        public function db_initialize() {
            $db = $this->db();
            $sql = [];
            $sql[] = 'DELETE FROM users WHERE id > 1000';
            $sql[] = 'DELETE FROM posts WHERE id > 10000';
            $sql[] = 'DELETE FROM comments WHERE id > 100000';
            $sql[] = 'UPDATE users SET del_flg = 0';
            $sql[] = 'UPDATE users SET del_flg = 1 WHERE id % 50 = 0';
            foreach($sql as $s) {
                $db->query($s);
            }

            // パフォーマンス向上のためのインデックスを作成
            $this->create_indexes();

            // 新規投稿（id > 10000）の画像ファイルのみ削除
            $this->cleanup_new_images();
        }

        public function create_indexes() {
            $db = $this->db();
            $indexes = [
                // コメント取得の高速化: post_idでの検索に使用
                ['comments', 'idx_comments_post_id', 'post_id'],
                // 投稿一覧の高速化: created_at順のソートに使用
                ['posts', 'idx_posts_created_at', 'created_at DESC'],
                // ユーザー投稿取得の高速化: user_idでの検索に使用
                ['posts', 'idx_posts_user_id', 'user_id'],
                // コメントユーザー取得の高速化: user_idでの検索に使用
                ['comments', 'idx_comments_user_id', 'user_id'],
            ];

            foreach ($indexes as [$table, $index_name, $columns]) {
                try {
                    // インデックスが存在しない場合のみ作成
                    $db->exec("CREATE INDEX `{$index_name}` ON `{$table}` ({$columns})");
                } catch (PDOException $e) {
                    // インデックスが既に存在する場合は無視（Duplicate key name）
                }
            }
        }

        public function cleanup_new_images() {
            // id > 10000 の画像ファイルを削除
            $files = glob(IMAGE_DIR . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    $basename = basename($file);
                    if (preg_match('/^(\d+)\./', $basename, $matches)) {
                        $id = (int)$matches[1];
                        if ($id > 10000) {
                            unlink($file);
                        }
                    }
                }
            }
        }

        public function export_images_to_filesystem() {
            // DBから画像を取得してファイルに書き出す（バッチ処理でメモリ節約）
            $db = $this->db();
            $batch_size = 100;

            for ($offset = 0; $offset < 10000; $offset += $batch_size) {
                $ps = $db->prepare('SELECT id, mime, imgdata FROM posts WHERE id > ? AND id <= ? ORDER BY id');
                $ps->execute([$offset, $offset + $batch_size]);

                while ($row = $ps->fetch(PDO::FETCH_ASSOC)) {
                    $path = get_image_path($row['id'], $row['mime']);
                    if (!file_exists($path)) {
                        file_put_contents($path, $row['imgdata']);
                    }
                }
                $ps->closeCursor();
            }
        }

        public function fetch_first($query, ...$params) {
            $db = $this->db();
            $ps = $db->prepare($query);
            $ps->execute($params);
            $result = $ps->fetch();
            $ps->closeCursor();
            return $result;
        }

        public function try_login($account_name, $password) {
            $user = $this->fetch_first('SELECT * FROM users WHERE account_name = ? AND del_flg = 0', $account_name);
            if ($user !== false && calculate_passhash($user['account_name'], $password) == $user['passhash']) {
                return $user;
            } elseif ($user) {
                return null;
            } else {
                return null;
            }
        }

        public function get_session_user() {
            if (!isset($_SESSION['user'], $_SESSION['user']['id'])) {
                return null;
            }

            $user = $this->fetch_first('SELECT * FROM `users` WHERE `id` = ?', $_SESSION['user']['id']);

            return $user ?: null;
        }

        public function make_posts(array $results, $options = []) {
            $options += ['all_comments' => false];
            $all_comments = $options['all_comments'];

            if (empty($results)) {
                return [];
            }

            // 投稿IDを収集
            $post_ids = array_column($results, 'id');
            $post_user_ids = array_unique(array_column($results, 'user_id'));

            // 1. 投稿者を一括取得
            $users_by_id = $this->fetch_users_by_ids($post_user_ids);

            // del_flg = 0 のユーザーの投稿のみフィルタ（最大POSTS_PER_PAGE件）
            $filtered_results = [];
            foreach ($results as $post) {
                $user = $users_by_id[$post['user_id']] ?? null;
                if ($user && $user['del_flg'] == 0) {
                    $filtered_results[] = $post;
                    if (count($filtered_results) >= POSTS_PER_PAGE) {
                        break;
                    }
                }
            }

            if (empty($filtered_results)) {
                return [];
            }

            // フィルタ後の投稿IDで再収集
            $post_ids = array_column($filtered_results, 'id');

            // 2. コメント数を一括取得
            $comment_counts = $this->fetch_comment_counts($post_ids);

            // 3. コメントを一括取得
            $comments_by_post = $this->fetch_comments_for_posts($post_ids, $all_comments);

            // 4. コメントユーザーIDを収集して一括取得
            $comment_user_ids = [];
            foreach ($comments_by_post as $comments) {
                foreach ($comments as $comment) {
                    $comment_user_ids[] = $comment['user_id'];
                }
            }
            $comment_user_ids = array_unique($comment_user_ids);
            $comment_users_by_id = $this->fetch_users_by_ids($comment_user_ids);

            // 5. データを組み立て
            $posts = [];
            foreach ($filtered_results as $post) {
                $post['comment_count'] = $comment_counts[$post['id']] ?? 0;

                $comments = $comments_by_post[$post['id']] ?? [];
                foreach ($comments as &$comment) {
                    $comment['user'] = $comment_users_by_id[$comment['user_id']] ?? null;
                }
                unset($comment);
                $post['comments'] = array_reverse($comments);

                $post['user'] = $users_by_id[$post['user_id']];
                $posts[] = $post;
            }

            return $posts;
        }

        // ユーザーをID配列から一括取得
        private function fetch_users_by_ids(array $user_ids) {
            if (empty($user_ids)) {
                return [];
            }
            $placeholder = implode(',', array_fill(0, count($user_ids), '?'));
            $ps = $this->db()->prepare("SELECT * FROM `users` WHERE `id` IN ({$placeholder})");
            $ps->execute(array_values($user_ids));
            $users = $ps->fetchAll(PDO::FETCH_ASSOC);

            $users_by_id = [];
            foreach ($users as $user) {
                $users_by_id[$user['id']] = $user;
            }
            return $users_by_id;
        }

        // コメント数を投稿ID配列から一括取得
        private function fetch_comment_counts(array $post_ids) {
            if (empty($post_ids)) {
                return [];
            }
            $placeholder = implode(',', array_fill(0, count($post_ids), '?'));
            $ps = $this->db()->prepare("SELECT `post_id`, COUNT(*) AS `count` FROM `comments` WHERE `post_id` IN ({$placeholder}) GROUP BY `post_id`");
            $ps->execute(array_values($post_ids));
            $rows = $ps->fetchAll(PDO::FETCH_ASSOC);

            $counts = [];
            foreach ($rows as $row) {
                $counts[$row['post_id']] = (int)$row['count'];
            }
            return $counts;
        }

        // コメントを投稿ID配列から一括取得
        private function fetch_comments_for_posts(array $post_ids, bool $all_comments) {
            if (empty($post_ids)) {
                return [];
            }
            $placeholder = implode(',', array_fill(0, count($post_ids), '?'));

            if ($all_comments) {
                // 全コメント取得
                $ps = $this->db()->prepare("SELECT * FROM `comments` WHERE `post_id` IN ({$placeholder}) ORDER BY `post_id`, `created_at` DESC");
                $ps->execute(array_values($post_ids));
            } else {
                // 各投稿につき最新3件のコメントを取得（ウィンドウ関数使用）
                $sql = "
                    SELECT * FROM (
                        SELECT *, ROW_NUMBER() OVER (PARTITION BY `post_id` ORDER BY `created_at` DESC) AS rn
                        FROM `comments`
                        WHERE `post_id` IN ({$placeholder})
                    ) ranked
                    WHERE rn <= 3
                    ORDER BY `post_id`, `created_at` DESC
                ";
                $ps = $this->db()->prepare($sql);
                $ps->execute(array_values($post_ids));
            }

            $rows = $ps->fetchAll(PDO::FETCH_ASSOC);

            $comments_by_post = [];
            foreach ($rows as $row) {
                unset($row['rn']); // ウィンドウ関数で追加されたカラムを削除
                $comments_by_post[$row['post_id']][] = $row;
            }
            return $comments_by_post;
        }

    };
});

AppFactory::setContainer($container);
$app = AppFactory::create();

// ------- helper method for view

function escape_html($h) {
    return htmlspecialchars($h, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function redirect(Response $response, $location, $status) {
    return $response->withStatus($status)->withHeader('Location', $location);
}

function image_url($post) {
    $ext = '';
    if ($post['mime'] === 'image/jpeg') {
        $ext = '.jpg';
    } else if ($post['mime'] === 'image/png') {
        $ext = '.png';
    } else if ($post['mime'] === 'image/gif') {
        $ext = '.gif';
    }
    return "/image/{$post['id']}{$ext}";
}

function validate_user($account_name, $password) {
    if (!(preg_match('/\A[0-9a-zA-Z_]{3,}\z/', $account_name) && preg_match('/\A[0-9a-zA-Z_]{6,}\z/', $password))) {
        return false;
    }
    return true;
}

function digest($src) {
    // opensslのバージョンによっては (stdin)= というのがつくので取る
    $src = escapeshellarg($src);
    return trim(`printf "%s" {$src} | openssl dgst -sha512 | sed 's/^.*= //'`);
}

function calculate_salt($account_name) {
    return digest($account_name);
}

function calculate_passhash($account_name, $password) {
    $salt = calculate_salt($account_name);
    return digest("{$password}:{$salt}");
}

// --------

$app->get('/initialize', function (Request $request, Response $response) {
    $this->get('helper')->db_initialize();
    return $response;
});

// 画像を事前にDBからファイルシステムにエクスポート（ベンチマーク前に1回実行）
$app->get('/export-images', function (Request $request, Response $response) {
    $this->get('helper')->export_images_to_filesystem();
    $response->getBody()->write('Images exported successfully');
    return $response;
});

$app->get('/login', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user() !== null) {
        return redirect($response, '/', 302);
    }
    return $this->get('view')->render($response, 'login.php', [
        'me' => null,
        'flash' => $this->get('flash')->getFirstMessage('notice'),
    ]);
});

$app->post('/login', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user() !== null) {
        return redirect($response, '/', 302);
    }

    $db = $this->get('db');
    $params = $request->getParsedBody();
    $user = $this->get('helper')->try_login($params['account_name'], $params['password']);

    if ($user) {
        $_SESSION['user'] = [
            'id' => $user['id'],
        ];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
        return redirect($response, '/', 302);
    } else {
        $this->get('flash')->addMessage('notice', 'アカウント名かパスワードが間違っています');
        return redirect($response, '/login', 302);
    }
});

$app->get('/register', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user() !== null) {
        return redirect($response, '/', 302);
    }
    return $this->get('view')->render($response, 'register.php', [
        'me' => null,
        'flash' => $this->get('flash')->getFirstMessage('notice'),
    ]);
});


$app->post('/register', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user()) {
        return redirect($response, '/', 302);
    }

    $params = $request->getParsedBody();
    $account_name = $params['account_name'];
    $password = $params['password'];

    $validated = validate_user($account_name, $password);
    if (!$validated) {
        $this->get('flash')->addMessage('notice', 'アカウント名は3文字以上、パスワードは6文字以上である必要があります');
        return redirect($response, '/register', 302);
    }

    $user = $this->get('helper')->fetch_first('SELECT 1 FROM users WHERE `account_name` = ?', $account_name);
    if ($user) {
        $this->get('flash')->addMessage('notice', 'アカウント名がすでに使われています');
        return redirect($response, '/register', 302);
    }

    $db = $this->get('db');
    $ps = $db->prepare('INSERT INTO `users` (`account_name`, `passhash`) VALUES (?,?)');
    $ps->execute([
        $account_name,
        calculate_passhash($account_name, $password)
    ]);
    $_SESSION['user'] = [
        'id' => $db->lastInsertId(),
    ];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    return redirect($response, '/', 302);
});

$app->get('/logout', function (Request $request, Response $response) {
    unset($_SESSION['user']);
    unset($_SESSION['csrf_token']);
    return redirect($response, '/', 302);
});

$app->get('/', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    $db = $this->get('db');
    // JOINでdel_flg=0のユーザーの投稿のみ取得し、LIMITで件数制限
    $ps = $db->prepare('
        SELECT p.`id`, p.`user_id`, p.`body`, p.`mime`, p.`created_at`
        FROM `posts` p
        JOIN `users` u ON p.`user_id` = u.`id`
        WHERE u.`del_flg` = 0
        ORDER BY p.`created_at` DESC
        LIMIT ?
    ');
    $ps->execute([POSTS_PER_PAGE]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results);

    return $this->get('view')->render($response, 'index.php', [
        'posts' => $posts,
        'me' => $me,
        'flash' => $this->get('flash')->getFirstMessage('notice'),
    ]);
});

$app->get('/posts', function (Request $request, Response $response) {
    $params = $request->getQueryParams();
    $max_created_at = $params['max_created_at'] ?? null;
    $db = $this->get('db');
    // JOINでdel_flg=0のユーザーの投稿のみ取得し、LIMITで件数制限
    $ps = $db->prepare('
        SELECT p.`id`, p.`user_id`, p.`body`, p.`mime`, p.`created_at`
        FROM `posts` p
        JOIN `users` u ON p.`user_id` = u.`id`
        WHERE u.`del_flg` = 0 AND p.`created_at` <= ?
        ORDER BY p.`created_at` DESC
        LIMIT ?
    ');
    $ps->execute([$max_created_at, POSTS_PER_PAGE]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results);

    return $this->get('view')->render($response, 'posts.php', ['posts' => $posts]);
});

$app->get('/posts/{id}', function (Request $request, Response $response, $args) {
    $db = $this->get('db');
    $ps = $db->prepare('SELECT * FROM `posts` WHERE `id` = ?');
    $ps->execute([$args['id']]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results, ['all_comments' => true]);

    if (count($posts) == 0) {
        $response->getBody()->write('404');
        return $response->withStatus(404);
    }

    $post = $posts[0];

    $me = $this->get('helper')->get_session_user();

    return $this->get('view')->render($response, 'post.php', ['post' => $post, 'me' => $me]);
});

$app->post('/', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/', 302);
    }

    $params = $request->getParsedBody();
    if (($params['csrf_token'] ?? null) !== $_SESSION['csrf_token']) {
        $response->getBody()->write('422');
        return $response->withStatus(422);
    }

    if ($_FILES['file']) {
        // セキュリティ: ファイルタイプの検証（jpeg/png/gifのみ許可）
        $mime = '';
        if (strpos($_FILES['file']['type'], 'jpeg') !== false) {
            $mime = 'image/jpeg';
        } elseif (strpos($_FILES['file']['type'], 'png') !== false) {
            $mime = 'image/png';
        } elseif (strpos($_FILES['file']['type'], 'gif') !== false) {
            $mime = 'image/gif';
        } else {
            $this->get('flash')->addMessage('notice', '投稿できる画像形式はjpgとpngとgifだけです');
            return redirect($response, '/', 302);
        }

        // セキュリティ: ファイルサイズ制限（10MB以下）
        if (strlen(file_get_contents($_FILES['file']['tmp_name'])) > UPLOAD_LIMIT) {
            $this->get('flash')->addMessage('notice', 'ファイルサイズが大きすぎます');
            return redirect($response, '/', 302);
        }

        $db = $this->get('db');
        $query = 'INSERT INTO `posts` (`user_id`, `mime`, `imgdata`, `body`) VALUES (?,?,?,?)';
        $ps = $db->prepare($query);
        $ps->execute([
          $me['id'],
          $mime,
          '',  // imgdataは空にする（ファイルシステムに保存するため）
          $params['body'],
        ]);
        $pid = $db->lastInsertId();

        // 画像をファイルシステムに保存
        // セキュリティ: ファイル名は {post_id}.{ext} 形式で生成（ユーザー入力不使用、パストラバーサル対策済み）
        $image_path = get_image_path($pid, $mime);
        move_uploaded_file($_FILES['file']['tmp_name'], $image_path);

        return redirect($response, "/posts/{$pid}", 302);
    } else {
        $this->get('flash')->addMessage('notice', '画像が必須です');
        return redirect($response, '/', 302);
    }
});

// 画像配信エンドポイント
// 注: Nginxで直接配信される場合はこのエンドポイントは呼ばれない（フォールバック用）
$app->get('/image/{id}.{ext}', function (Request $request, Response $response, $args) {
    if ($args['id'] == 0) {
        return $response;
    }

    // 拡張子からMIMEタイプを決定（許可された形式のみ）
    $ext = $args['ext'];
    $mime = match ($ext) {
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        default => ''
    };

    if ($mime === '') {
        $response->getBody()->write('404');
        return $response->withStatus(404);
    }

    // ファイルシステムを優先してチェック（高速）
    $image_path = IMAGE_DIR . "/{$args['id']}.{$ext}";
    if (file_exists($image_path)) {
        $response->getBody()->write(file_get_contents($image_path));
        return $response->withHeader('Content-Type', $mime);
    }

    // ファイルがなければDBから取得（フォールバック：マイグレーション中の互換性確保）
    $post = $this->get('helper')->fetch_first('SELECT mime, imgdata FROM `posts` WHERE `id` = ?', $args['id']);

    if ($post && $post['mime'] === $mime && !empty($post['imgdata'])) {
        $response->getBody()->write($post['imgdata']);
        return $response->withHeader('Content-Type', $post['mime']);
    }

    $response->getBody()->write('404');
    return $response->withStatus(404);
});

$app->post('/comment', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/', 302);
    }

    $params = $request->getParsedBody();
    if (($params['csrf_token'] ?? null) !== $_SESSION['csrf_token']) {
        $response->getBody()->write('422');
        return $response->withStatus(422);
    }

    if (!preg_match('/\A[0-9]+\z/', $params['post_id'])) {
        $response->getBody()->write('post_idは整数のみです');
        return $response;
    }
    $post_id = $params['post_id'];

    $query = 'INSERT INTO `comments` (`post_id`, `user_id`, `comment`) VALUES (?,?,?)';
    $ps = $this->get('db')->prepare($query);
    $ps->execute([
        $post_id,
        $me['id'],
        $params['comment']
    ]);

    return redirect($response, "/posts/{$post_id}", 302);
});

$app->get('/admin/banned', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/login', 302);
    }

    if ($me['authority'] == 0) {
        $response->getBody()->write('403');
        return $response->withStatus(403);
    }

    $db = $this->get('db');
    $ps = $db->prepare('SELECT * FROM `users` WHERE `authority` = 0 AND `del_flg` = 0 ORDER BY `created_at` DESC');
    $ps->execute();
    $users = $ps->fetchAll(PDO::FETCH_ASSOC);

    return $this->get('view')->render($response, 'banned.php', ['users' => $users, 'me' => $me]);
});

$app->post('/admin/banned', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/', 302);
    }

    if ($me['authority'] == 0) {
        $response->getBody()->write('403');
        return $response->withStatus(403);
    }

    $params = $request->getParsedBody();
    if (($params['csrf_token'] ?? null) !== $_SESSION['csrf_token']) {
        $response->getBody()->write('422');
        return $response->withStatus(422);
    }

    $db = $this->get('db');
    $query = 'UPDATE `users` SET `del_flg` = ? WHERE `id` = ?';
    foreach ($params['uid'] as $id) {
        $ps = $db->prepare($query);
        $ps->execute([1, $id]);
    }

    return redirect($response, '/admin/banned', 302);
});

$app->get('/@{account_name}', function (Request $request, Response $response, $args) {
    $db = $this->get('db');
    $user = $this->get('helper')->fetch_first('SELECT * FROM `users` WHERE `account_name` = ? AND `del_flg` = 0', $args['account_name']);

    if ($user === false) {
        $response->getBody()->write('404');
        return $response->withStatus(404);
    }

    // 投稿を取得（LIMITで件数制限）
    $ps = $db->prepare('SELECT `id`, `user_id`, `body`, `created_at`, `mime` FROM `posts` WHERE `user_id` = ? ORDER BY `created_at` DESC LIMIT ?');
    $ps->execute([$user['id'], POSTS_PER_PAGE]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results);

    // ユーザーが書いたコメント数
    $comment_count = $this->get('helper')->fetch_first('SELECT COUNT(*) AS count FROM `comments` WHERE `user_id` = ?', $user['id'])['count'];

    // ユーザーの投稿数
    $post_count = $this->get('helper')->fetch_first('SELECT COUNT(*) AS count FROM `posts` WHERE `user_id` = ?', $user['id'])['count'];

    // ユーザーの投稿に付けられたコメント数（サブクエリで効率化）
    $commented_count = $this->get('helper')->fetch_first('
        SELECT COUNT(*) AS count FROM `comments`
        WHERE `post_id` IN (SELECT `id` FROM `posts` WHERE `user_id` = ?)
    ', $user['id'])['count'];

    $me = $this->get('helper')->get_session_user();

    return $this->get('view')->render($response, 'user.php', ['posts' => $posts, 'user' => $user, 'post_count' => $post_count, 'comment_count' => $comment_count, 'commented_count'=> $commented_count, 'me' => $me]);
});

$app->run();
