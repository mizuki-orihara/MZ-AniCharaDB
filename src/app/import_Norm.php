<?php
/**
 * Anim_DB:レシーバー　通常用
 * 役割:　 外部からのJSONデータを受信し、セッションごとの一時フォルダに保存する。
 * 設置想定パス:　/app/import_Norm.php
 * キャッシュ配置先/~temp_[SID]
 * 受け入れファイルサイズ(32KB) = 32767byte;
 * DnDファイル群一回の上限サイズ(1MB)= 1048576byte;
 * 1回の受け入れファイル数　表示上　30個
 * 1回の受け入れファイル個数(隠しマージン込み)32個
 * 受け入れファイル形式: JSON
 * レポート記入ファイル: import_Norm_status.json
 **/

 // 定数定義参照先
 require_once __DIR__ . '/config.php';

 /** task_master.jsonからのゲート開閉状態の読み出し（APIディレクトリ）
 * gates内receiveがtureなら続行可
 **/
 $task_master = json_decode(file_get_contents(__DIR__ . '/api/task_master.json'), true);
 if($task_master['gates']['receive'] === true){
	 // ゲートが開いている場合、続行


	 // SIDはブラウザクッキーに残したUUIDと日時を基準に
	 // そのためのUUIDクッキーの有無の確認と、テンポラリーユーザー用時限付きクッキー(30日)の生成
	 if(!isset($_COOKIE['UUID'])){
		 // UUIDクッキーがない場合、新規生成してクッキーに保存
		 $uuid = uniqid('', true); // ユニークなIDを生成
		 setcookie('UUID', $uuid, time() + (30 * 24 * 60 * 60), '/'); // 30日間有効なクッキーを設定
	 } else {
		 // UUIDクッキーがある場合、その値を使用
		 $uuid = $_COOKIE['UUID'];
	 }
		// SIDをUUIDと現在のタイムスタンプで生成(241231_235959形式)
		$sid = $uuid . '_' . date('Ymd_His');

		// 受信したJSONデータをセッションごとの一時フォルダに保存
		// 受信データの取得
		$input_data = file_get_contents('php://input');
		// データサイズの確認
		if (strlen($input_data) > 32767) {
			// データサイズが32KBを超える場合、エラーレスポンスを返す
			http_response_code(400);
			echo json_encode(['error' => 'File size exceeds the limit of 32KB.']);
			exit;
		}
		// セッションごとの一時フォルダのパスを生成　app/01a_import_Norm/~temp_[SID]
		$temp_dir = __DIR__ . '/01a_import_Norm/~temp_' . $sid;
		// フォルダの作成
		if (!file_exists($temp_dir)) {
			mkdir($temp_dir, 0777, true);
		}
		/**ファイルの受信と保存。
		 * 各ファイルのファイル名は受信元ファイルから変更せず、拡張子のみ確認し、JSON以外は破棄
		 **/
		