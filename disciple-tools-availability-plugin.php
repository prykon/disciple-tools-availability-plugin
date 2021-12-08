<?php
/**
 *Plugin Name: Disciple.Tools - Availability
 * Plugin URI: https://github.com/prykon/disciple-tools-availability-plugin
 * Description: Easily find the best time slot for a group with many members.
 * Version:  1.1.3
 * Author URI: https://github.com/prykon
 * GitHub Plugin URI: https://github.com/prykon/dt-availability-plugin
 * Requires at least: 4.7.0
 * (Requires 4.7+ because of the integration of the REST API at 4.7 and the security requirements of this milestone version.)
 * Tested up to: 5.6
 *
 * @package Disciple_Tools
 * @link    https://github.com/DiscipleTools
 * @license GPL-2.0 or later
 *          https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

add_action( 'after_setup_theme', function (){
    new DT_Contact_Availability();
});

class DT_Contact_Availability {
    public static $required_dt_theme_version = '1.0.0';
    public static $rest_namespace = null; //use if you have custom rest endpoints on this plugin
    public static $plugin_name = "Availability";

    public function scripts() {
            wp_enqueue_script( 'amcharts-core', 'https://www.amcharts.com/lib/4/core.js', false, '4' );
            wp_enqueue_script( 'amcharts-charts', 'https://www.amcharts.com/lib/4/charts.js', false, '4' );
            wp_enqueue_script( 'amcharts-animated', 'https://www.amcharts.com/lib/4/themes/animated.js', [ 'amcharts-core' ], '4' );
    }

    public function __construct() {
        $wp_theme = wp_get_theme();
        $version = $wp_theme->version;
        /*
         * Check if the Disciple.Tools theme is loaded and is the latest required version
         */
        $is_theme_dt = strpos( $wp_theme->get_template(), "disciple-tools-theme" ) !== false || $wp_theme->name === "Disciple Tools";
        if ( $is_theme_dt && version_compare( $version, self::$required_dt_theme_version, "<" ) ) {
            add_action( 'admin_notices', [ $this, 'dt_plugin_hook_admin_notice' ] );
            add_action( 'wp_ajax_dismissed_notice_handler', 'dt_hook_ajax_notice_handler' );
            return false;
        }
        if ( !$is_theme_dt ){
            return false;
        }

        /**
         * Load useful function from the theme
         */
        if ( !defined( 'DT_FUNCTIONS_READY' ) ){
            require_once get_template_directory() . '/dt-core/global-functions.php';
        }
        /*
         * Don't load the plugin on every rest request. Only those with the correct namespace
         * This restricts endpoints defined in this plugin this namespace
         */
//        require_once( 'includes/dt-hooks.php' );

        $this->plugin_hooks();
    }

    private function plugin_hooks(){

        add_filter( 'dt_details_additional_tiles', 'dt_details_additional_tiles', 10, 2 );

        function dt_details_additional_tiles( $tiles, $post_type = "" ) {

            if ( $post_type === "contacts" ) {
                $tiles["contact_availability"] = [ "label" => __( "Availability", 'disciple_tools' ) ];
            }

            if ( $post_type === "groups" ) {
                $tiles["group_availability"] = [ "label" => __( "Group availability", 'disciple_tools' ) ];
            }
            return $tiles;
        }


        add_filter( "dt_custom_fields_settings", "dt_contact_fields", 1, 2 );

        function dt_contact_fields( array $fields, string $post_type = "") {
            if ( $post_type === "contacts" ) {
                $options = [
                    "morning" => [ "label" => __( "morning", 'disciple_tools' ) ],
                    "noon" => [ "label" => __( "noon", 'disciple_tools' ) ],
                    "evening" => [ "label" => __( "evening", 'disciple_tools' ) ],
                    "night" => [ "label" => __( "night", 'disciple_tools' ) ],
                ];

                $contact_availability_items = [
                    "Monday" => __( "Monday", 'disciple_tools' ),
                    "Tuesday" => __( "Tuesday", 'disciple_tools' ),
                    "Wednesday" => __( "Wednesday", 'disciple_tools' ),
                    "Thursday" => __( "Thursday", 'disciple_tools' ),
                    "Friday" => __( "Friday", 'disciple_tools' ),
                    "Saturday" => __( "Saturday", 'disciple_tools' ),
                    "Sunday" => __( "Sunday", 'disciple_tools' ),
                ];

                foreach ( $contact_availability_items as $item_key => $item_label ) {
                    $fields["contact_availability_" . dt_create_field_key( $item_key ) ] = [
                        "name" => $item_label,
                        "default" => $options,
                        "tile" => "contact_availability",
                        "type" => "multi_select",
                        "hidden" => true,
                        "custom_display" => true,
                    ];
                }
            }
            return $fields;
        }

        add_action( "dt_details_additional_section", "dt_add_section", 30, 2 );
        add_action( 'wp_enqueue_scripts', [ $this, 'scripts' ], 99 );

        /** Gets availability for a specific member */
        function get_member_availability( int $member_id, string $member_name ) {
            global $wpdb;

            $results = $wpdb->get_results( $wpdb->prepare( "
                SELECT meta_key, meta_value
                FROM $wpdb->postmeta
                WHERE meta_key LIKE %s
                AND post_id = %s;",
            'contact_availability_%', $member_id ), ARRAY_A );

            $member_availability = [];

            foreach ( $results as $key => $value ) {
                if ( $value != '' ) {
                    $timeslot = str_replace( 'contact_availability_', '', $value['meta_key'] ) . '_' . $value['meta_value'];
                    $member_availability[$key]['timeslot'] = $timeslot;
                    $member_availability[$key]['post_title'] = $member_name;
                }
            }

            return $member_availability;
        }

        function dt_add_section( $section, $post_type ) {

            /** Tile for contacts page*/
            if ( $section === "contact_availability" && $post_type === "contacts" ) {
                $post_fields = DT_Posts::get_post_field_settings( $post_type );
                $post = DT_Posts::get_post( $post_type, get_the_ID() );

                $total_done = 0;
                $total = 0;
                foreach ($post_fields as $field_key => $field_options ) {
                    if ( isset( $field_options["tile"] ) && $field_options["tile"] === "contact_availability" ) {
                        $total += sizeof( $field_options["default"] );
                        if ( isset( $post[$field_key] ) ) {
                            $total_done += sizeof( $post[$field_key] );
                        }
                    }
                }

                foreach ($post_fields as $field_key => $field_options ) :
                    if ( isset( $field_options["tile"] ) && $field_options["tile"] === "contact_availability" ) :
                        $post_fields[$field_key]["hidden"] = false;
                        $post_fields[$field_key]["custom_display"] = false;
                        ?>
                        <div style="display: flex">
                            <div style="flex-grow: 1; overflow: hidden; white-space: nowrap; text-overflow: ellipsis">
                                <?php echo esc_html( $field_options["name"] ); ?>
                            </div>
                            <div style="">
                                <div class="small button-group" style="display: inline-block; margin-bottom: 5px">
                                    <?php foreach ( $post_fields[$field_key]["default"] as $option_key => $option_value ): ?>
                                        <?php
                                        $class = ( in_array( $option_key, $post[$field_key] ?? [] ) ) ?
                                            "selected-select-button" : "empty-select-button"; ?>
                                      <button id="<?php echo esc_html( $option_key ) ?>" type="button" data-field-key="<?php echo esc_html( $field_key ); ?>"
                                              class="dt_multi_select <?php echo esc_html( $class ) ?> select-button button " style="padding:5px">
                                          <?php echo esc_html( $post_fields[$field_key]["default"][$option_key]["label"] ) ?>
                                      </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif;
                endforeach;
            }

            /** Tile for groups page*/
            if ( $section === "group_availability" && $post_type === "groups" ) {
                /** Get all group data */
                $group = DT_Posts::get_post( $post_type, get_the_ID() );

                /** Get group member data */
                $group_members = [];
                $availabilities_pretty = [];
                foreach ( $group['members'] as $key => $value ) {

                    /** Get member id */
                    $group_members[$key]['ID'] = $value['ID'];

                    /** Get member name */
                    $group_members[$key]['post_title'] = $value['post_title'];

                    /** Get member availabilities */
                    $group_members[$key]['availability'] = get_member_availability( $value['ID'], $value['post_title'] );

                    /** Save availabilities to array */
                    foreach ( $group_members[$key]['availability'] as $ma) {
                        if ( empty( $availabilities_pretty[ $ma['timeslot'] ] ) ) {
                            $availabilities_pretty[ $ma['timeslot'] ][] = $ma['post_title'];
                            $availabilities_pretty[ $ma['timeslot'] . '_count' ] = 1;
                        } else {
                            $availabilities_pretty[ $ma['timeslot'] ][] = $ma['post_title'];
                            $availabilities_pretty[ $ma['timeslot'] . '_count'] ++;
                        }
                    }
                }
                ?>
<div>
    <!-- Styles -->
<style>
#chartdiv {
  width: 100%;
  height: 400px;
}

</style>

<!-- Chart code -->
<script>
am4core.ready(function() {

// Themes begin
am4core.useTheme(am4themes_animated);
// Themes end

var chart = am4core.create("chartdiv", am4charts.XYChart);
chart.maskBullets = false;

var xAxis = chart.xAxes.push(new am4charts.CategoryAxis());
var yAxis = chart.yAxes.push(new am4charts.CategoryAxis());

xAxis.dataFields.category = "weekday";
yAxis.dataFields.category = "hour";

xAxis.renderer.grid.template.disabled = false;
xAxis.renderer.minGridDistance = 1;
xAxis.renderer.opposite = true;



var label = xAxis.renderer.labels.template;
label.rotation = -45;
label.maxWidth = 1;



yAxis.renderer.grid.template.disabled = false;
//yAxis.renderer.inversed = true;
yAxis.renderer.minGridDistance = 1;

var series = chart.series.push(new am4charts.ColumnSeries());
series.dataFields.categoryX = "weekday";
series.dataFields.categoryY = "hour";
series.dataFields.value = "value";
series.dataFields.people = "people";
series.sequencedInterpolation = true;
series.defaultState.transitionDuration = 3000;

var bgColor = new am4core.InterfaceColorSet().getFor("background");

var columnTemplate = series.columns.template;
columnTemplate.strokeWidth = 1;
columnTemplate.strokeOpacity = 1;
columnTemplate.stroke = '#a6a6a6';
columnTemplate.tooltipHTML = "<b>{weekday} {hour}</b><br><small>({value.workingValue.formatNumber('#.')} out of <?php echo count( $group['members'] ); ?> members available)</small>\n {people}";
columnTemplate.width = am4core.percent(100);
columnTemplate.height = am4core.percent(100);

series.heatRules.push({
  target: columnTemplate,
  property: "fill",
  min: am4core.color(bgColor),
  max: chart.colors.getIndex(0)
});

// heat legend
var heatLegend = chart.bottomAxesContainer.createChild(am4charts.HeatLegend);
heatLegend.width = am4core.percent(100);
heatLegend.series = series;
heatLegend.valueAxis.renderer.labels.template.fontSize = 9;
heatLegend.valueAxis.renderer.minGridDistance = 30;

// heat legend behavior
series.columns.template.events.on("over", function(event) {
  handleHover(event.target);
})

series.columns.template.events.on("hit", function(event) {
  handleHover(event.target);
})

function handleHover(column) {
  if (!isNaN(column.dataItem.value)) {
    heatLegend.valueAxis.showTooltipAt(column.dataItem.value)
  }
  else {
    heatLegend.valueAxis.hideTooltip();
  }
}

series.columns.template.events.on("out", function(event) {
  heatLegend.valueAxis.hideTooltip();
})

chart.data = [
                <?php
                    $weekdays = [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ];
                    $timeframe = [ 'morning', 'noon', 'evening', 'night' ];
                    $availabilities = [];
                    $i = 0;

                foreach ( $weekdays as $day ) {
                    foreach ( $timeframe as $time ):
                        $timeslot = strtolower( $day ) . "_" . $time;
                        $timeslot_value = '';
                        $timeslot_people = [];

                        if ( ! isset( $availabilities_pretty[ $timeslot . '_count' ] ) ) {
                            $timeslot_value = 0;
                        } else {
                            $timeslot_value = 0 + $availabilities_pretty[ $timeslot . '_count' ];
                        }

                        if ( ! isset( $availabilities_pretty[ $timeslot ] ) ) {
                            $timeslot_people = [];
                        } else {
                            $timeslot_people = $availabilities_pretty[ $timeslot ];
                        }
                        ?>
    {
    "hour": '<?php echo esc_html( $time );?>',
    "weekday": '<?php echo esc_html( $day );?>',
    "value": <?php echo esc_html( $timeslot_value ); ?>,
    "people": '<?php
    foreach ( $timeslot_people as $tp ) {
        echo '<li>' . esc_html( $tp ) . '</li>';
    }
    ?>',
    },
<?php endforeach;} ?>
];

}); // end am4core.ready()
</script>

<!-- HTML -->
<div id="chartdiv"></div></div>
                <?php
            }
        }
    }

    public function dt_plugin_hook_admin_notice() {
        $wp_theme = wp_get_theme();
        $current_version = $wp_theme->version;
        $message = "'Disciple.Tools - Availability' plugin requires 'Disciple.Tools' theme to work. Please activate 'Disciple.Tools' theme or make sure it is latest version.";
        if ( strpos( $wp_theme->get_template(), "disciple-tools-theme" ) !== false || $wp_theme->name === "Disciple Tools" ) {
            $message .= sprintf( esc_html__( 'Current Disciple.Tools version: %1$s, required version: %2$s', 'dt_plugin' ), esc_html( $current_version ), esc_html( self::$required_dt_theme_version ) );
        }
        $key = dt_create_field_key( self::$plugin_name );
        // Check if it's been dismissed...
        if ( ! get_option( 'dismissed-' . $key, false ) ) { ?>
            <div class="notice notice-error notice-<?php echo esc_html( $key ); ?> is-dismissible" data-notice="<?php echo esc_html( $key ); ?>">
                <p><?php echo esc_html( $message );?></p>
            </div>
            <script>
              jQuery(function($) {
                $( document ).on( 'click', '.notice-<?php echo esc_html( $key ); ?> .notice-dismiss', function () {
                  $.ajax( ajaxurl, {
                    type: 'POST',
                    data: {
                      action: 'dismissed_notice_handler',
                      type: '<?php echo esc_html( $key ); ?>',
                      security: '<?php echo esc_html( wp_create_nonce( 'wp_rest_dismiss' ) ) ?>'
                    }
                  })
                });
              });
            </script>
        <?php }
    }

}

/**
 * AJAX handler to store the state of dismissible notices.
 */
if ( !function_exists( "dt_hook_ajax_notice_handler" )){
    function dt_hook_ajax_notice_handler(){
        check_ajax_referer( 'wp_rest_dismiss', 'security' );
        if ( isset( $_POST["type"] ) ){
            $type = sanitize_text_field( wp_unslash( $_POST["type"] ) );
            update_option( 'dismissed-' . $type, true );
        }
    }
}

/**
 * Check for plugin updates even when the active theme is not Disciple.Tools
 *
 * Below is the publicly hosted .json file that carries the version information. This file can be hosted
 * anywhere as long as it is publicly accessible. You can download the version file listed below and use it as
 * a template.
 * Also, see the instructions for version updating to understand the steps involved.
 * @see https://github.com/DiscipleTools/disciple-tools-version-control/wiki/How-to-Update-the-Starter-Plugin
 */
add_action( 'plugins_loaded', function (){
    if ( is_admin() ){
        // Check for plugin updates
        if ( ! class_exists( 'Puc_v4_Factory' ) ) {
            if ( file_exists( get_template_directory() . '/dt-core/libraries/plugin-update-checker/plugin-update-checker.php' )){
                require( get_template_directory() . '/dt-core/libraries/plugin-update-checker/plugin-update-checker.php' );
            }
        }
        if ( class_exists( 'Puc_v4_Factory' ) ){
            Puc_v4_Factory::buildUpdateChecker(
                'https://raw.githubusercontent.com/prykon/disciple-tools-availability-plugin/master/version-control.json',
                __FILE__,
                'disciple-tools-availability-plugin'
            );
        }
    }
} );
