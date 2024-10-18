<?php

/*
Plugin Name: WP ChatGPT Custom Queries
Description: Un plugin para mostrar respuestas de ChatGPT basadas en consultas personalizadas del usuario y almacenadas en la base de datos.
Version: 1.7
Author: Leandro Diaz, Daniel Vargas, Sebastian Zuñiga
*/

// Crear las tablas al activar el plugin
function create_chatgpt_tables() {
    global $wpdb;

    // Crear tabla para consultas
    $queries_table = $wpdb->prefix . 'chatgpt_queries';
    $charset_collate = $wpdb->get_charset_collate();

    $sql_queries = "CREATE TABLE IF NOT EXISTS $queries_table (
        id INT(11) NOT NULL AUTO_INCREMENT,
        query_name VARCHAR(255) NOT NULL,
        query TEXT NOT NULL,
        is_active TINYINT(1) DEFAULT 0,
        PRIMARY KEY (id)
    ) $charset_collate;";

    // Crear tabla para respuestas
    $responses_table = $wpdb->prefix . 'chatgpt_responses';
    $sql_responses = "CREATE TABLE IF NOT EXISTS $responses_table (
        id INT(11) NOT NULL AUTO_INCREMENT,
        query_id INT(11) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        response TEXT NOT NULL,
        PRIMARY KEY (id),
        FOREIGN KEY (query_id) REFERENCES $queries_table(id) ON DELETE CASCADE
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_queries);
    dbDelta($sql_responses);
}

register_activation_hook(__FILE__, 'create_chatgpt_tables');


// Código para el CRON que obtiene una respuesta de ChatGPT y la almacena en la base de datos
function get_chatgpt_response() {
    global $wpdb;
    $queries_table = $wpdb->prefix . 'chatgpt_queries';
    $active_queries = $wpdb->get_results("SELECT * FROM $queries_table WHERE is_active = 1");

    foreach ($active_queries as $query) {
        $api_url = 'https://api.openai.com/v1/chat/completions';
        $api_key = get_option('chatgpt_api_key');

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => 'gpt-3.5-turbo',
                'messages' => array(
                    array('role' => 'user', 'content' => $query->query)
                ),
                'max_tokens' => 60
            ))
        );

        $response = wp_remote_post($api_url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $wpdb->insert(
                $wpdb->prefix . 'chatgpt_responses',
                array(
                    'query_id' => $query->id,
                    'response' => 'Error: ' . sanitize_text_field($error_message),
                    'created_at' => current_time('mysql')
                )
            );
            continue; // Continúa con la siguiente consulta
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (isset($result['choices'][0]['message']['content'])) {
            $wpdb->insert(
                $wpdb->prefix . 'chatgpt_responses',
                array(
                    'query_id' => $query->id,
                    'response' => sanitize_text_field($result['choices'][0]['message']['content']),
                    'created_at' => current_time('mysql')
                )
            );
        } else {
            $error_message = "No se recibió ninguna respuesta válida de la API.";
            $wpdb->insert(
                $wpdb->prefix . 'chatgpt_responses',
                array(
                    'query_id' => $query->id,
                    'response' => sanitize_text_field($error_message),
                    'created_at' => current_time('mysql')
                )
            );
        }
    }
}



// Añadir el intervalo personalizado basado en la opción guardada
function add_custom_interval($schedules) {
    $cron_interval_hours = get_option('chatgpt_cron_interval', 2); // 2 horas por defecto
    $schedules['custom_interval'] = array(
        'interval' => $cron_interval_hours * 3600, // Convertir horas a segundos
        'display' => __('Cada ' . $cron_interval_hours . ' horas')
    );
    return $schedules;
}
add_filter('cron_schedules', 'add_custom_interval');
// Función para reprogramar el CRON con el nuevo intervalo
function reprogram_chatgpt_cron($cron_interval_hours) {
    if (wp_next_scheduled('generate_chatgpt_response_event')) {
        wp_clear_scheduled_hook('generate_chatgpt_response_event');
    }
    wp_schedule_event(time(), 'custom_interval', 'generate_chatgpt_response_event');
}


/* Funcion antigua del cron de 2 minutos
// Función para agregar el intervalo de cron de 2 minutos
function add_two_minutes_interval($schedules) {
    $schedules['two_minutes'] = array(
        'interval' => 120,
        'display' => __('Cada 2 minutos')
    );
    return $schedules;
}
add_filter('cron_schedules', 'add_two_minutes_interval');
*/


function activate_chatgpt_plugin() {
    if (!wp_next_scheduled('generate_chatgpt_response_event')) {
        wp_schedule_event(time(), 'custom_interval', 'generate_chatgpt_response_event');
    }
}
register_activation_hook(__FILE__, 'activate_chatgpt_plugin');


// Acción para el evento CRON
add_action('generate_chatgpt_response_event', 'get_chatgpt_response');

// Función para agregar el menú de configuración
function chatgpt_queries_menu() {
    add_options_page(
        'Configuración de Consultas ChatGPT', // Título de la página
        'Consultas ChatGPT', // Título del menú
        'manage_options', // Capacidad
        'chatgpt-queries', // Slug
        'chatgpt_queries_options_page' // Función que muestra la página
    );
}
add_action('admin_menu', 'chatgpt_queries_menu');

// Clase para crear el Widget de ChatGPT
class ChatGPT_Response_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'chatgpt_response_widget', // ID único del widget
            __('ChatGPT Response', 'text_domain'), // Nombre del widget
            array('description' => __('Muestra la respuesta de una consulta de ChatGPT.', 'text_domain')) // Descripción del widget
        );
    }

    // Función para mostrar el contenido del widget en el frontend
    public function widget($args, $instance) {
        global $wpdb;
        $queries_table = $wpdb->prefix . 'chatgpt_queries';
        $responses_table = $wpdb->prefix . 'chatgpt_responses'; 
        $selected_query_id = isset($instance['query_id']) ? intval($instance['query_id']) : 0; 
    
        if ($selected_query_id) {
            // Activar la consulta seleccionada
            $wpdb->update($queries_table, array('is_active' => 1), array('id' => $selected_query_id));
    
            // Obtener la consulta seleccionada
            $query = $wpdb->get_row($wpdb->prepare("SELECT * FROM $queries_table WHERE id = %d", $selected_query_id));
    
            if ($query) {
                // Obtener la respuesta más reciente para la consulta seleccionada
                $response = $wpdb->get_var($wpdb->prepare("SELECT response FROM $responses_table WHERE query_id = %d ORDER BY created_at DESC LIMIT 1", $selected_query_id));
    
                // Mostrar el contenido del widget
                echo $args['before_widget'];
                
                if (!empty($response)) {
                    // Usar clases del tema de WordPress y permitir que herede estilos
                    echo $args['before_title'] . apply_filters('widget_title', esc_html($query->query_name)) . $args['after_title'];
                    echo '<div class="chatgpt-response">';
                    echo '<p>' . esc_html($response) . '</p>';
                    echo '</div>';
                } else {
                    echo '<p>No hay respuestas disponibles para esta consulta.</p>';
                }
                
                echo $args['after_widget'];
            } else {
                echo '<p>Consulta seleccionada no encontrada.</p>';
            }
        } else {
            echo '<p>No se ha seleccionado ninguna consulta.</p>';
        }
    }
    


    // Función para mostrar el formulario de configuración en el backend
    public function form($instance) {
        global $wpdb;
        $queries_table = $wpdb->prefix . 'chatgpt_queries';

        // Obtener las consultas disponibles desde la base de datos
        $queries = $wpdb->get_results("SELECT * FROM $queries_table");

        // Obtener el ID de la consulta seleccionada
        $selected_query_id = isset($instance['query_id']) ? intval($instance['query_id']) : 0;

        // Mostrar el formulario de selección de consulta
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('query_id'); ?>"><?php _e('Selecciona la consulta:'); ?></label>
            <select name="<?php echo $this->get_field_name('query_id'); ?>" id="<?php echo $this->get_field_id('query_id'); ?>" class="widefat">
                <option value="0"><?php _e('Seleccionar una consulta'); ?></option>
                <?php foreach ($queries as $query): ?>
                    <option value="<?php echo esc_attr($query->id); ?>" <?php selected($selected_query_id, $query->id); ?>>
                        <?php echo esc_html($query->query_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <?php
    }

    // Función para guardar los ajustes del widget
    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['query_id'] = (!empty($new_instance['query_id'])) ? intval($new_instance['query_id']) : 0;
        return $instance;
    }
}

// Registrar el widget
function register_chatgpt_response_widget() {
    register_widget('ChatGPT_Response_Widget');
}
add_action('widgets_init', 'register_chatgpt_response_widget');


// Función para mostrar la página de opciones
function chatgpt_queries_options_page() {
    global $wpdb;
    $queries_table = $wpdb->prefix . 'chatgpt_queries';
    $table_name = $queries_table;



// Obtener respuestas de la base de datos
$responses_table = $wpdb->prefix . 'chatgpt_responses';
$responses = $wpdb->get_results("SELECT r.id, r.response, r.created_at, q.query_name FROM $responses_table r JOIN $queries_table q ON r.query_id = q.id ORDER BY r.created_at DESC");
 // Reindexar las respuestas
        $responses = $wpdb->get_results("SELECT id FROM $responses_table ORDER BY id ASC");
        foreach ($responses as $index => $response) {
            $new_id = $index + 1; // +1 para empezar desde 1
            if ($response->id != $new_id) {
                $wpdb->update($responses_table, array('id' => $new_id), array('id' => $response->id));
            }
        }
    // Manejar la eliminación de respuestas
    if (isset($_POST['delete_response'])) {
        $response_id = intval($_POST['delete_response']);
        $wpdb->delete($responses_table, array('id' => $response_id));

        // Reindexar las respuestas
        $responses = $wpdb->get_results("SELECT id FROM $responses_table ORDER BY id ASC");
        foreach ($responses as $index => $response) {
            $new_id = $index + 1; // +1 para empezar desde 1
            if ($response->id != $new_id) {
                $wpdb->update($responses_table, array('id' => $new_id), array('id' => $response->id));
            }
        }
    }
// Obtener respuestas de la base de datos
$responses_table = $wpdb->prefix . 'chatgpt_responses';
$responses = $wpdb->get_results("SELECT r.id, r.response, r.created_at, q.query_name FROM $responses_table r JOIN $queries_table q ON r.query_id = q.id ORDER BY r.created_at DESC");
// Manejar la edición de respuestas
if (isset($_POST['edit_response_id']) && isset($_POST['edit_response'])) {
    $edit_response_id = intval($_POST['edit_response_id']);
    $edit_response = sanitize_textarea_field($_POST['edit_response']);
    $wpdb->update($responses_table, array('response' => $edit_response), array('id' => $edit_response_id));
}
// Obtener respuestas de la base de datos
$responses_table = $wpdb->prefix . 'chatgpt_responses';
$responses = $wpdb->get_results("SELECT r.id, r.response, r.created_at, q.query_name FROM $responses_table r JOIN $queries_table q ON r.query_id = q.id ORDER BY r.created_at DESC");



// Manejar la actualización de la API Key
if (isset($_POST['api_key']) && !empty($_POST['api_key'])) {
    $api_key = sanitize_text_field($_POST['api_key']);
    $result = update_option('chatgpt_api_key', $api_key);

    // Verificar si la API Key se guardó correctamente
    if ($result) {
        $success_message = "API Key guardada correctamente.";
    } else {
        $error_message = "Error al guardar la API Key.";
    }
}

// Obtener el valor actual de la API Key
$current_api_key = get_option('chatgpt_api_key', '');



// Manejar la actualización del intervalo de CRON
if (isset($_POST['cron_interval_hours'])) {
    $cron_interval_hours = intval($_POST['cron_interval_hours']);
    update_option('chatgpt_cron_interval', $cron_interval_hours);
    reprogram_chatgpt_cron($cron_interval_hours);
}

// Obtener el valor actual del intervalo
$current_cron_interval = get_option('chatgpt_cron_interval', 2);

  // Manejar la creación de nuevas consultas
if (isset($_POST['new_query_name']) && isset($_POST['new_query'])) {
    $new_query_name = sanitize_text_field($_POST['new_query_name']);
    $new_query = sanitize_textarea_field($_POST['new_query']);

    // Obtener el ID más bajo disponible
    $available_ids = $wpdb->get_col("SELECT id FROM $table_name ORDER BY id ASC");
    $new_id = 1; // Comenzar desde 1
    while (in_array($new_id, $available_ids)) {
        $new_id++; // Incrementar hasta encontrar un ID disponible
    }

    // Insertar la nueva consulta como inactiva
    $wpdb->insert($table_name, array(
        'id' => $new_id,
        'query_name' => $new_query_name,
        'query' => $new_query,
        'is_active' => 0
    ));
}


      // Manejar la eliminación de consultas
      if (isset($_POST['delete_query'])) {
        $query_id = intval($_POST['delete_query']);
        $wpdb->delete($queries_table, array('id' => $query_id));

        // Reindexar las consultas
        $queries = $wpdb->get_results("SELECT id FROM $queries_table ORDER BY id ASC");
        foreach ($queries as $index => $query) {
            $new_id = $index + 1; // +1 para empezar desde 1
            if ($query->id != $new_id) {
                $wpdb->update($queries_table, array('id' => $new_id), array('id' => $query->id));
            }
        }
    }

// Manejar la activación/desactivación de consultas
if (isset($_POST['toggle_active'])) {
    $query_id = intval($_POST['toggle_active']);
    $current_query = $wpdb->get_row("SELECT * FROM $table_name WHERE id = $query_id");

    // Cambiar el estado de la consulta seleccionada
    $new_status = $current_query->is_active ? 0 : 1; // Cambiar el estado
    $wpdb->update($table_name, array('is_active' => $new_status), array('id' => $query_id));
}



    // Manejar la edición de consultas
    if (isset($_POST['edit_query_id']) && isset($_POST['edit_query_name']) && isset($_POST['edit_query'])) {
        $edit_query_id = intval($_POST['edit_query_id']);
        $edit_query_name = sanitize_text_field($_POST['edit_query_name']);
        $edit_query = sanitize_textarea_field($_POST['edit_query']);

        $wpdb->update($table_name, array('query_name' => $edit_query_name, 'query' => $edit_query), array('id' => $edit_query_id));
    }

  // Obtener el orden de las consultas desde la URL
  $order_by = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'id';
  $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'ASC';

  // Validar el orden y el campo de orden
  $valid_orderby = array('query_name', 'created_at');
  if (!in_array($order_by, $valid_orderby)) {
      $order_by = 'id';
  }
  if (!in_array($order, array('ASC', 'DESC'))) {
      $order = 'ASC';
  }

  // Obtener las consultas con el orden especificado
  $queries = $wpdb->get_results("SELECT * FROM $table_name ORDER BY $order_by $order");
    ?>

    <div class="wrap">
        <h1>Configuración de Consultas ChatGPT</h1>

        <form method="post" style="margin-bottom: 20px;">
            <h2>Añadir Nueva Consulta</h2>
            <label for="new_query_name">Nombre de la Consulta:</label>
            <input type="text" name="new_query_name" required>
            <label for="new_query">Consulta:</label>
            <textarea name="new_query" required></textarea>
            <input type="submit" value="Añadir Consulta">
        </form>
<!-- Formulario para definir el intervalo del CRON -->
<form method="post" style="margin-bottom: 20px;">
    <h2>Configurar Intervalo del CRON</h2>
    <label for="cron_interval_hours">Intervalo en horas:</label>
    <input type="number" name="cron_interval_hours" value="<?php echo esc_attr($current_cron_interval); ?>" required>
    <input type="submit" value="Guardar Intervalo">
</form>
<!-- Formulario para la API Key -->
<form method="post" style="margin-bottom: 20px;">
    <h2>Configurar API Key</h2>
    <label for="api_key">API Key:</label>
    <input type="text" id="api_key" name="api_key" value="<?php echo esc_attr($current_api_key); ?>" required>
    <input type="submit" value="Guardar">
</form>
<!-- Mensaje de exito del ingreso de la API Key -->

<?php if (isset($success_message)) : ?>
    <div class="updated notice">
        <p><?php echo esc_html($success_message); ?></p>
    </div>
<?php endif; ?>



        <h2>Consultas Existentes</h2>
  <!-- Filtros de ordenación -->
  <div class="tablenav top">
            <div class="alignleft actions">
                <a href="?page=chatgpt-queries&orderby=query_name&order=ASC" class="button">Ordenar A-Z</a>
                <a href="?page=chatgpt-queries&orderby=query_name&order=DESC" class="button">Ordenar Z-A</a>
                <a href="?page=chatgpt-queries&orderby=id&order=ASC" class="button">Más Antiguas</a>
                <a href="?page=chatgpt-queries&orderby=id&order=DESC" class="button">Más Recientes</a>
            </div>
        </div>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="5%">#</th>
                    <th width="15%">Nombre</th>
                    <th width="40%">GPT Prompt</th>
                    <th width="15%">Estado</th>
                    <th width="25%">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($queries): ?>
                    <?php foreach ($queries as $query): ?>
                        <tr>
                            <td><?php echo esc_html($query->id); ?></td>
                            <td>
                                <?php if (isset($_POST['edit_query_id']) && $_POST['edit_query_id'] == $query->id): ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="edit_query_id" value="<?php echo esc_attr($query->id); ?>">
                                        <label for="edit_query_name">Nombre de la Consulta:</label>
                                        <input type="text" name="edit_query_name" value="<?php echo esc_attr($query->query_name); ?>" required>
                                        <label for="edit_query">Consulta:</label>
                                        <textarea name="edit_query" required><?php echo esc_html($query->query); ?></textarea>
                                        <input type="submit" value="Guardar Cambios">
                                        <input type="button" value="Cerrar Menú" onclick="location.href='<?php echo esc_url(admin_url('options-general.php?page=chatgpt-queries')); ?>'">
                                    </form>
                                <?php else: ?>
                                    <?php echo esc_html($query->query_name); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($query->query); ?></td>
                            <td style="color: <?php echo $query->is_active ? 'green' : 'red'; ?>;"><?php echo $query->is_active ? 'Activa' : 'Inactiva'; ?></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="toggle_active" value="<?php echo esc_attr($query->id); ?>">
                                    <button type="submit" title="<?php echo $query->is_active ? 'Desactivar' : 'Activar'; ?>">
                                        <span class="dashicons dashicons-<?php echo $query->is_active ? 'no' : 'yes'; ?>-alt"></span>
                                    </button>
                                </form>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="delete_query" value="<?php echo esc_attr($query->id); ?>">
                                    <button type="submit" title="Eliminar" onclick="return confirm('¿Estás seguro de que deseas eliminar esta consulta?');">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </form>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="edit_query_id" value="<?php echo esc_attr($query->id); ?>">
                                    <button type="submit" title="Editar">
                                        <span class="dashicons dashicons-edit"></span>
                                    </button>
                                </form>
                            </td>
                            
                        </tr>
                        
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">No hay consultas disponibles.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h2>Respuestas a Consultas</h2>
<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th width="5%">#</th>
            <th width="50%">Respuesta</th>
            <th width="25%">Consulta</th>
            <th width="20%">Acciones</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($responses): ?>
            <?php foreach ($responses as $response): ?>
                <tr>
                    <td><?php echo esc_html($response->id); ?></td>
                    <td>
                        <?php if (isset($_POST['edit_response_id']) && $_POST['edit_response_id'] == $response->id): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="edit_response_id" value="<?php echo esc_attr($response->id); ?>">
                                <textarea name="edit_response" required><?php echo esc_html($response->response); ?></textarea>
                                <input type="submit" value="Guardar Cambios">
                                <input type="button" value="Cerrar Menú" onclick="location.href='<?php echo esc_url(admin_url('options-general.php?page=chatgpt-queries')); ?>'">
                            </form>
                        <?php else: ?>
                            <?php echo esc_html($response->response); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($response->query_name); ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="delete_response" value="<?php echo esc_attr($response->id); ?>">
                            <button type="submit" title="Eliminar" onclick="return confirm('¿Estás seguro de que deseas eliminar esta respuesta?');">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        </form>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="edit_response_id" value="<?php echo esc_attr($response->id); ?>">
                            <button type="submit" title="Editar">
                                <span class="dashicons dashicons-edit"></span>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="4"><?php _e('No hay respuestas disponibles.'); ?></td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<?php
}