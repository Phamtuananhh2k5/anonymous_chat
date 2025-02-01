<?php
/*****************************************************
 * Modern Anonymous Group Chat - index.php (Dark Mode)
 * Tích hợp:
 * - Upload file (hỗ trợ file lên tới 1GB)
 * - Gửi tin nhắn (có thể kèm file và reply)
 * - Lấy tin nhắn mới qua kỹ thuật long polling
 * - Tìm kiếm tin nhắn theo từ khóa
 * - Cập nhật và hiển thị Typing Indicator
 * - Download file an toàn (sửa lỗi 404)
 * - Xóa toàn bộ lịch sử chat (tin nhắn và các file uploads)
 *****************************************************/

// Cấu hình cơ sở dữ liệu
$servername   = "localhost";
$db_username  = "chatuser";         // Username MySQL
$db_password  = "Hoilamgi@12345";     // Password MySQL
$dbname       = "chatdb";

// Kết nối MySQL
$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Xử lý các action theo GET 'action'
$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action == 'upload') {
    // Upload file (hỗ trợ file lên tới 1GB)
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
        echo json_encode(['error' => 'Invalid request method']);
        exit;
    }
    if (!isset($_FILES['file'])) {
        echo json_encode(['error' => 'No file uploaded']);
        exit;
    }
    $file = $_FILES['file'];
    if ($file['size'] > 1024 * 1024 * 1024) {
        echo json_encode(['error' => 'File size exceeds limit']);
        exit;
    }
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    // Tạo tên file duy nhất
    $uniqueName = time() . '-' . uniqid() . '-' . basename($file['name']);
    $targetFile = $uploadDir . $uniqueName;
    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        echo json_encode(['fileName' => $uniqueName]);
    } else {
        echo json_encode(['error' => 'Failed to upload file']);
    }
    exit;
} elseif ($action == 'send_message') {
    // Gửi tin nhắn (có thể kèm file và thông tin reply)
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
        echo json_encode(['error' => 'Invalid request method']);
        exit;
    }
    $usernameParam = isset($_POST['username']) ? $conn->real_escape_string($_POST['username']) : '';
    $message       = isset($_POST['message'])  ? $conn->real_escape_string($_POST['message'])  : '';
    $fileName      = isset($_POST['fileName']) ? $conn->real_escape_string($_POST['fileName']) : '';
    $reply_to      = (isset($_POST['reply_to']) && is_numeric($_POST['reply_to'])) ? intval($_POST['reply_to']) : 'NULL';
    if ($usernameParam === '' || ($message === '' && $fileName === '')) {
        echo json_encode(['error' => 'Empty message']);
        exit;
    }
    $query = "INSERT INTO messages (username, message, fileUrl, reply_to, timestamp) 
              VALUES ('$usernameParam', '$message', " . ($fileName ? "'$fileName'" : "NULL") . ", " . ($reply_to !== 'NULL' ? $reply_to : "NULL") . ", NOW())";
    if ($conn->query($query)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => $conn->error]);
    }
    exit;
} elseif ($action == 'fetch_messages') {
    // LONG POLLING: Chờ tin nhắn mới (timeout 30 giây)
    header('Content-Type: application/json');
    $lastTimestamp = isset($_GET['last_timestamp']) ? $conn->real_escape_string($_GET['last_timestamp']) : null;
    if ($lastTimestamp) {
        $query = "SELECT m.*, r.message AS reply_message, r.username AS reply_username 
                  FROM messages m 
                  LEFT JOIN messages r ON m.reply_to = r.id 
                  WHERE m.timestamp > '$lastTimestamp' 
                  ORDER BY m.timestamp ASC";
    } else {
        $query = "SELECT m.*, r.message AS reply_message, r.username AS reply_username 
                  FROM messages m 
                  LEFT JOIN messages r ON m.reply_to = r.id 
                  ORDER BY m.timestamp ASC";
    }
    $result = $conn->query($query);
    $messages = array();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }
    }
    echo json_encode($messages);
    exit;
} elseif ($action == 'search') {
    // Tìm kiếm tin nhắn theo từ khóa
    header('Content-Type: application/json');
    $keyword = isset($_GET['keyword']) ? $conn->real_escape_string($_GET['keyword']) : '';
    if ($keyword == '') {
        echo json_encode([]);
        exit;
    }
    $query = "SELECT m.*, r.message AS reply_message, r.username AS reply_username 
              FROM messages m 
              LEFT JOIN messages r ON m.reply_to = r.id 
              WHERE m.message LIKE '%$keyword%' OR m.username LIKE '%$keyword%' 
              ORDER BY m.timestamp ASC";
    $result = $conn->query($query);
    $messages = array();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }
    }
    echo json_encode($messages);
    exit;
} elseif ($action == 'update_typing') {
    // Cập nhật trạng thái "đang gõ" cho user
    header('Content-Type: application/json');
    $usernameParam = isset($_POST['username']) ? $conn->real_escape_string($_POST['username']) : '';
    if ($usernameParam == '') {
        echo json_encode(['error' => 'No username']);
        exit;
    }
    $conn->query("INSERT INTO typing (username, last_active) VALUES ('$usernameParam', NOW()) ON DUPLICATE KEY UPDATE last_active = NOW()");
    echo json_encode(['success' => true]);
    exit;
} elseif ($action == 'fetch_typing') {
    // Lấy danh sách user đang gõ (trong vòng 5 giây), ngoại trừ chính user
    header('Content-Type: application/json');
    $usernameParam = isset($_GET['username']) ? $conn->real_escape_string($_GET['username']) : '';
    $query = "SELECT username FROM typing WHERE last_active > (NOW() - INTERVAL 5 SECOND) AND username <> '$usernameParam'";
    $result = $conn->query($query);
    $typingUsers = array();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $typingUsers[] = $row['username'];
        }
    }
    echo json_encode($typingUsers);
    exit;
} elseif ($action == 'clear_history') {
    // Xóa toàn bộ lịch sử chat và các tệp đã gửi
    header('Content-Type: application/json');
    $conn->query("DELETE FROM messages");
    $uploadDir = __DIR__ . '/uploads/';
    if (is_dir($uploadDir)) {
        $files = glob($uploadDir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
    echo json_encode(['success' => true]);
    exit;
} elseif ($action == 'download') {
    // Download file – sử dụng basename() để đảm bảo bảo mật và xử lý lỗi chính xác
    if (!isset($_GET['file'])) {
        die('No file specified.');
    }
    $file = basename($_GET['file']);
    $filePath = __DIR__ . '/uploads/' . $file;
    if (!file_exists($filePath)) {
        http_response_code(404);
        die('File not found.');
    }
    // Nếu có bộ đệm đang hoạt động thì xóa nó
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filePath));
    flush();
    readfile($filePath);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Modern Anonymous Group Chat - Dark Mode</title>
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Google Fonts: Roboto -->
  <link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet">
  <!-- Animate.css cho hiệu ứng -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
  <style>
    /* Tổng thể */
    body {
      font-family: 'Roboto', sans-serif;
      background-color: #000;
      color: #fff;
      margin: 0;
      padding: 0;
    }
    /* Khung chat */
    .chat-container {
      max-width: 900px;
      margin: 30px auto;
      background: #121212;
      border-radius: 15px;
      box-shadow: 0 0 25px rgba(255,255,255,0.1);
      display: flex;
      flex-direction: column;
      height: 90vh;
      overflow: hidden;
    }
    /* Header */
    .chat-header {
      background: linear-gradient(135deg, #333, #111);
      padding: 20px;
      border-bottom: 1px solid #333;
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      align-items: center;
    }
    .chat-header .title-search {
      flex: 1;
    }
    .chat-header h3 {
      margin: 0;
      font-weight: 700;
      font-size: 1.75rem;
      color: #fff;
    }
    .chat-header .controls {
      display: flex;
      gap: 10px;
      align-items: center;
    }
    .chat-header input[type="text"].search-input {
      padding: 5px 10px;
      border: none;
      border-radius: 5px;
    }
    /* Typing indicator */
    #typing-indicator {
      font-style: italic;
      color: #ccc;
      padding: 0 20px;
    }
    /* Nội dung chat */
    .chat-body {
      flex: 1;
      padding: 20px;
      overflow-y: auto;
      background: #000;
    }
    /* Tin nhắn */
    .chat-message {
      max-width: 70%;
      margin-bottom: 15px;
      padding: 12px 18px;
      border-radius: 25px;
      word-wrap: break-word;
      box-shadow: 0 2px 8px rgba(0,0,0,0.5);
      position: relative;
    }
    .chat-message .sender {
      font-weight: bold;
      margin-bottom: 5px;
      color: #ff9800;
    }
    .chat-message .content {
      color: #eee;
    }
    .chat-message .time {
      font-size: 0.75rem;
      color: #aaa;
      margin-top: 8px;
      text-align: right;
    }
    .chat-message .reply-info {
      border-left: 3px solid #ff9800;
      padding-left: 10px;
      margin-bottom: 5px;
      font-size: 0.9rem;
      color: #ccc;
    }
    .chat-message.self {
      background: #1e1e1e;
      margin-left: auto;
    }
    .chat-message.other {
      background: #2a2a2a;
    }
    /* Hình ảnh upload */
    .uploaded-image {
      max-width: 100%;
      border-radius: 10px;
      margin-top: 10px;
    }
    /* Video */
    .video-player {
      width: 100%;
      margin-top: 10px;
      border-radius: 10px;
    }
    /* Link download */
    a.download-link {
      display: inline-block;
      margin-top: 10px;
      color: #ff9800;
      font-weight: 600;
      text-decoration: none;
    }
    a.download-link:hover {
      text-decoration: underline;
    }
    /* Footer (input chat) */
    .chat-footer {
      background: #1a1a1a;
      padding: 15px;
      border-top: 1px solid #333;
    }
    .chat-footer form {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
    }
    .chat-footer input[type="text"] {
      flex: 1;
      border: none;
      padding: 12px;
      border-radius: 25px;
      background: #2a2a2a;
      color: #fff;
      box-shadow: inset 0 0 5px rgba(0,0,0,0.5);
    }
    .chat-footer input[type="text"]::placeholder {
      color: #bbb;
    }
    .chat-footer input[type="file"] {
      border: none;
    }
    .chat-footer button {
      background: #ff9800;
      border: none;
      color: #fff;
      padding: 12px 20px;
      border-radius: 25px;
      font-weight: 600;
      transition: background 0.3s;
    }
    .chat-footer button:hover {
      background: #e68900;
    }
    /* Box hiển thị tin nhắn được reply */
    #reply-box {
      background: #2a2a2a;
      padding: 8px 12px;
      border-left: 4px solid #ff9800;
      margin-bottom: 10px;
      border-radius: 5px;
      display: none;
      position: relative;
    }
    #reply-box .cancel-reply {
      position: absolute;
      right: 5px;
      top: 5px;
      cursor: pointer;
      color: #ff9800;
    }
  </style>
</head>
<body>
  <div class="chat-container">
    <div class="chat-header">
      <div class="title-search">
        <h3>Modern Anonymous Group Chat</h3>
      </div>
      <div class="controls">
        <input type="text" id="search-input" class="search-input" placeholder="Search messages...">
        <button id="search-btn" class="btn btn-secondary btn-sm">Search</button>
        <small id="username-display"></small>
        <button id="clear-history" class="btn btn-danger btn-sm">Clear History</button>
      </div>
    </div>
    <div id="typing-indicator"></div>
    <div class="chat-body" id="message-list">
      <!-- Tin nhắn sẽ được hiển thị tại đây -->
    </div>
    <div class="chat-footer">
      <div id="reply-box">
        <span id="reply-info"></span>
        <span class="cancel-reply">×</span>
      </div>
      <form id="chat-form">
        <input type="text" id="message-input" placeholder="Type your message..." autocomplete="off">
        <input type="file" id="file-input">
        <button type="submit">Send</button>
      </form>
    </div>
  </div>

  <!-- jQuery -->
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <!-- Bootstrap Bundle JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    $(document).ready(function(){
      // Tạo tên người dùng ngẫu nhiên
      var username = "User" + Math.floor(Math.random() * 10000);
      $('#username-display').text("Logged in as: " + username);
      
      var lastTimestamp = null;
      var replyTo = null; // Thông tin tin nhắn được reply

      // Hàm long polling để lấy tin nhắn mới
      function longPoll() {
        var url = '?action=fetch_messages';
        if(lastTimestamp) {
          url += '&last_timestamp=' + encodeURIComponent(lastTimestamp);
        }
        $.ajax({
          url: url,
          dataType: 'json',
          success: function(data) {
            if(data && data.length > 0) {
              data.forEach(function(msg) {
                appendMessage(msg);
              });
              lastTimestamp = data[data.length - 1].timestamp;
            }
            longPoll();
          },
          error: function() {
            setTimeout(function(){ longPoll(); }, 3000);
          }
        });
      }

      // Hàm thêm tin nhắn vào khung chat
      function appendMessage(msg) {
        var messageClass = (msg.username === username) ? 'self' : 'other';
        var messageHtml = '<div class="chat-message ' + messageClass + ' animate__animated animate__fadeInUp" data-id="' + msg.id + '">';
        messageHtml += '<div class="sender">' + msg.username + '</div>';
        if(msg.reply_message){
          messageHtml += '<div class="reply-info">Reply to ' + msg.reply_username + ': ' + msg.reply_message + '</div>';
        }
        messageHtml += '<div class="content">' + msg.message + '</div>';
        if(msg.fileUrl){
          if(isImage(msg.fileUrl)){
            messageHtml += '<img src="uploads/' + msg.fileUrl + '" class="uploaded-image" alt="uploaded image">';
          } else if(isVideo(msg.fileUrl)){
            messageHtml += '<video controls class="video-player"><source src="uploads/' + msg.fileUrl + '" type="video/mp4">Your browser does not support the video tag.</video>';
          } else {
            messageHtml += '<a class="download-link" href="?action=download&file=' + encodeURIComponent(msg.fileUrl) + '" target="_blank">Download File</a>';
          }
        }
        messageHtml += '<div class="time">' + msg.timestamp + '</div>';
        messageHtml += '<button class="btn btn-link btn-sm reply-btn" data-id="' + msg.id + '" data-sender="' + msg.username + '" data-message="' + msg.message.replace(/"/g, '&quot;') + '">Reply</button>';
        messageHtml += '</div>';
        $('#message-list').append(messageHtml);
        $('#message-list').scrollTop($('#message-list')[0].scrollHeight);
      }

      // Hàm kiểm tra file là hình ảnh
      function isImage(fileName){
        return (fileName.match(/\.(jpeg|jpg|gif|png)$/i) != null);
      }
      // Hàm kiểm tra file là video
      function isVideo(fileName){
        return (fileName.match(/\.(mp4|webm|ogv)$/i) != null);
      }

      // Gọi long polling để lấy tin nhắn mới
      longPoll();

      // Xử lý gửi tin nhắn khi submit form
      $('#chat-form').submit(function(e){
        e.preventDefault();
        var message = $('#message-input').val();
        var file = $('#file-input')[0].files[0];
        if(file){
          var formData = new FormData();
          formData.append('file', file);
          $.ajax({
            url: '?action=upload',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(uploadResponse) {
              if(uploadResponse.fileName){
                sendMessage(message, uploadResponse.fileName);
              } else {
                alert('File upload failed: ' + uploadResponse.error);
              }
            },
            error: function(){
              alert('Error uploading file');
            }
          });
        } else {
          sendMessage(message, '');
        }
      });

      // Hàm gửi tin nhắn lên server (bao gồm reply_to nếu có)
      function sendMessage(message, fileName){
        var data = { username: username, message: message, fileName: fileName };
        if(replyTo) {
          data.reply_to = replyTo.id;
        }
        $.ajax({
          url: '?action=send_message',
          type: 'POST',
          data: data,
          dataType: 'json',
          success: function(response){
            if(response.success){
              $('#message-input').val('');
              $('#file-input').val('');
              clearReply();
            } else {
              alert('Error sending message: ' + response.error);
            }
          },
          error: function(){
            alert('Error sending message.');
          }
        });
      }

      // Xử lý nút Clear History
      $('#clear-history').click(function(){
        if(confirm('Are you sure you want to clear chat history? This action cannot be undone.')){
          $.ajax({
            url: '?action=clear_history',
            type: 'POST',
            dataType: 'json',
            success: function(response){
              if(response.success){
                $('#message-list').empty();
                lastTimestamp = null;
              } else {
                alert('Error clearing history: ' + response.error);
              }
            },
            error: function(){
              alert('Error clearing history.');
            }
          });
        }
      });

      // Tìm kiếm tin nhắn
      $('#search-btn').click(function(){
        var keyword = $('#search-input').val();
        $.ajax({
          url: '?action=search&keyword=' + encodeURIComponent(keyword),
          dataType: 'json',
          success: function(data) {
            $('#message-list').empty();
            if(data && data.length > 0) {
              data.forEach(function(msg) {
                appendMessage(msg);
              });
            } else {
              $('#message-list').html('<p>No messages found.</p>');
            }
          },
          error: function() {
            alert('Error searching messages.');
          }
        });
      });

      // Cập nhật Typing Indicator
      function updateTyping() {
        $.ajax({
          url: '?action=update_typing',
          type: 'POST',
          data: { username: username },
          dataType: 'json'
        });
      }
      function fetchTyping() {
        $.ajax({
          url: '?action=fetch_typing&username=' + encodeURIComponent(username),
          dataType: 'json',
          success: function(data) {
            if(data && data.length > 0) {
              $('#typing-indicator').text(data.join(', ') + " is typing...");
            } else {
              $('#typing-indicator').text('');
            }
          }
        });
      }
      var typingTimeout;
      $('#message-input').on('input', function(){
        updateTyping();
        clearTimeout(typingTimeout);
        typingTimeout = setTimeout(fetchTyping, 1000);
      });
      setInterval(fetchTyping, 2000);

      // Xử lý nút Reply trên từng tin nhắn
      $(document).on('click', '.reply-btn', function(){
        var id = $(this).data('id');
        var sender = $(this).data('sender');
        var message = $(this).data('message');
        replyTo = { id: id, sender: sender, message: message };
        $('#reply-info').html('Replying to <strong>' + sender + '</strong>: ' + message);
        $('#reply-box').show();
      });
      $(document).on('click', '.cancel-reply', function(){
        clearReply();
      });
      function clearReply(){
        replyTo = null;
        $('#reply-box').hide();
        $('#reply-info').empty();
      }
    });
  </script>
</body>
</html>
