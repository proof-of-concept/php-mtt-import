<?php
class todoimport {

  private $_config = array(
    'sender' => '/<mail@example\.com>/', // only import mails from this mail address (REGEX!)
    'todo_list_id' => '9', // id of todo list
    'mysql_host' => 'localhost',
    'mysql_database' => 'tododb',
    'mysql_username' => 'todouser',
    'mysql_password' => 'password',
    'mail_host' => '{imap.gmail.com:993/imap/ssl/novalidate-cert}',
    'mail_username' => 'todo@example.com',
    'mail_password' => 'p4ss#w0rd'
  );

  private $_mbox = NULL;

  private $_db = NULL;

  private $_mails = array();

  private $_tags = array();

  public function __construct() {
    $this->_mbox = imap_open(
      $this->_config['mail_host'],
      $this->_config['mail_username'],
      $this->_config['mail_password']
    );
    $sorted = imap_sort($this->_mbox, SORTDATE, 0);
    $total = imap_num_msg($this->_mbox);
    if ($total > 0) {
      $i = 0;
      while ($i < $total) {
        $mail = array();
        $headers = imap_fetchheader($this->_mbox, $sorted[$i]);
        // subject
        $matches = array();
        preg_match_all('/^Subject: (.*)/m', $headers, $matches);
        $mail['subject'] = $matches[1][0];
        // Sender
        $matches = array();
        preg_match_all('/^From: (.*)/m', $headers, $matches);
        $mail['sender'] = $matches[1][0];
        // time of mail 
        $matches = array();
        preg_match_all('/^Date: (.*)/m', $headers, $matches);
        $mail['time'] = strtotime($matches[1][0]);
        // mail body
        $mail['body'] = $this->_getBody($sorted[$i]);
        $this->_mails[$i] = $mail;
        imap_delete($this->_mbox, $sorted[$i]);
        $i++;
      }
    }
    imap_expunge($this->_mbox);
    imap_close($this->_mbox);
  }

  public function import() {
    if (empty($this->_mails)) {
      return 0;
    }
    $this->_connectDb();
    $this->_loadTags();
    foreach ($this->_mails as $mail) {
      if (preg_match($this->_config['sender'], $mail['sender'])) {
        $mail['uuid'] = $this->_genUuid();
        $mail['tagids'] = array();
        $mail['tags'] = array();
        $matches = array();
        preg_match_all('/#([^\s]+)/', $mail['subject'], $matches);
        if (!empty($matches[1])) {
          foreach ($matches[0] as $tag) {
            $mail['subject'] = str_replace($tag, '', $mail['subject']);
          }
          $mail['subject'] = preg_replace('/\s\s+/',' ', $mail['subject']);
          $mail['tags'] = $tags[1];
        }
        foreach ($mail['tags'] as $tag) {
          $mail['tagids'][] = isset($this->_tags[$tag]) ? $this->_tags[$tag] : $this->_addMissingTag($tag);
        }
        $this->_applyTodoTags(
          $mail['tagids'],
          $this->_createTodo($mail)
        );
      }
    }
    $this->_disconnectDb();
    return count($this->_mails);
  }

  private function _connectDb() {
    if (is_null($this->_db)) {
      $this->_db = mysql_connect(
        $this->_config['mysql_host'],
        $this->_config['mysql_username'],
        $this->_config['mysql_password']
      );
      mysql_select_db($this->_config['mysql_database'], $this->_db);
    }
  }

  private function _disconnectDb() {
    if (!is_null($this->_db)) {
      mysql_close($this->_db);
    }
  }

  private function _loadTags() {
    if (empty($this->_tags)) {
      $result = mysql_query("SELECT * FROM mtt_tags", $this->_db);
      if ($result && mysql_num_rows($result) > 0) {
        while ($row = mysql_fetch_assoc($result)) {
          $this->_tags[$row['name']] = $row['id'];
        }
      }
    }
  }

  private function _addMissingTag($name) {
    mysql_query(
      sprintf(
        "INSERT INTO mtt_tags (name) VALUES ('%s')",
        mysql_real_escape_string($tag, $this->_db)
      ),
      $this->_db
    );
    return mysql_insert_id($this->_db);
  }

  private function _createTodo($todo) {
    mysql_query(
      sprintf(
        "INSERT INTO mtt_todolist (uuid, list_id, d_created, d_completed, d_edited, compl, title, note, tags, tags_ids) VALUES ('%s', '%d', '%d', 0, '%d', 0, '%s', '%s', '%s', '%s')",
        mysql_real_escape_string($todo['uuid'], $this->_db),
        mysql_real_escape_string($this->_config['todo_list_id'], $this->_db),
        mysql_real_escape_string($todo['time'], $this->_db),
        mysql_real_escape_string($todo['time'], $this->_db),
        mysql_real_escape_string($todo['subject'], $this->_db),
        mysql_real_escape_string($todo['body'], $this->_db),
        mysql_real_escape_string(implode(',', $todo['tags']), $this->_db),
        mysql_real_escape_string(implode(',', $todo['tagids']), $this->_db)
      ),
      $this->_db
    );
    return mysql_insert_id($this->_db);
  }

  private function _applyTodoTags($tagIds, $todoId) {
    foreach ($tagIds as $tagId) {
      mysql_query(
        sprintf(
          "INSERT INTO mtt_tag2task (tag_id, task_id, list_id) VALUES ('%d', '%d', '%d')",
          mysql_real_escape_string($tagId, $this->_db),
          mysql_real_escape_string($todoId, $this->_db),
          mysql_real_escape_string($this->_config['todo_list_id'], $this->_db)
        ),
        $this->_db
      );
    }
  }

  private function _getBody($mid) {
    $struct = imap_fetchstructure($this->_mbox, $mid);
    $parts = $struct->parts;
    $i = 0;
    if (!$parts) { /* Simple message, only 1 piece */
      $content = imap_body($this->_mbox, $mid);
    } else { /* Complicated message, multiple parts */
      $endwhile = false;
      $stack = array(); /* Stack while parsing message */
      $content = '';    /* Content of message */
      while (!$endwhile) {
        if (!isset($parts[$i])) {
          if (count($stack) > 0) {
            $parts = $stack[count($stack)-1]['p'];
            $i = $stack[count($stack)-1]['i'] + 1;
            array_pop($stack);
          } else {
            $endwhile = true;
          }
        }
        if (!$endwhile) {
          /* Create message part first (example '1.2.3') */
          $partstring = '';
          foreach ($stack as $s) {
            $partstring .= ($s['i'] + 1) . '.';
          }
          $partstring .= ($i + 1);

          if (!empty($parts[$i]->subtype) &&
              strtoupper($parts[$i]->subtype) == 'PLAIN') {
            $content .= imap_fetchbody($this->_mbox, $mid, $partstring);
          }
        }

        if (isset($parts[$i]) && !empty($parts[$i]->parts)) {
          if ($parts[$i]->subtype != 'RELATED') {
            // a glitch: embedded email message have one additional stack in the structure with subtype 'RELATED', but this stack is not present when using imap_fetchbody() to fetch parts.
            $stack[] = array('p' => $parts, 'i' => $i);
          }
          $parts = $parts[$i]->parts;
          $i = 0;
        } else {
          $i++;
        }
      } /* while */
    }
    return quoted_printable_decode($content);
  }

  private function _genUuid() {
    return sprintf(
      '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
      // 32 bits for "time_low"
      mt_rand(0, 0xffff), mt_rand(0, 0xffff),
      // 16 bits for "time_mid"
      mt_rand(0, 0xffff),
      // 16 bits for "time_hi_and_version",
      // four most significant bits holds version number 4
      mt_rand(0, 0x0fff) | 0x4000,
      // 16 bits, 8 bits for "clk_seq_hi_res",
      // 8 bits for "clk_seq_low",
      // two most significant bits holds zero and one for variant DCE1.1
      mt_rand(0, 0x3fff) | 0x8000,
      // 48 bits for "node"
      mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
  }
}

$todos = new todoimport();
echo sprintf('Imported %d mail(s).', $todos->import());
