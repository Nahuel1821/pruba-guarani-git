<?php
namespace siu\operaciones\inscripcion_cursos;

use SIU\Chulupi\kernel;
use SIU\Chulupi\util\mail;
use SIU\Chulupi\util\validador;
use siu\errores\error_guarani;
use siu\errores\error_guarani_seguridad;
use siu\extension_kernel\controlador_g3w2;
use siu\guarani;
use siu\modelo\_comun\comunes;
use siu\modelo\datos\catalogo;
use siu\modelo\entidades\parametro;
use siu\modelo_g3\cobro;
use siu\modelo\transacciones\inscripcion_cursada;

class controlador extends controlador_g3w2
{

    /**
     * @var generador_comprobantes_cursos
     */
    protected $generador_comp;

    /**
     * @return \siu\modelo\transacciones\cursos
     */
    function modelo()
    {
        return guarani::cursos();
    }

    function ini()
    {
        $this->generador_comp = kernel::localizador()->instanciar('operaciones\inscripcion_cursos\generador_comprobantes_cursos');

    }

    function accion__index()
    {
    }

    function decodificar_propuesta_curso($hash) {
        $datos = $this->modelo()->info__propuestas_administran_cursos();
        if(!empty($datos)) {
            foreach($datos as $value){
                if($value[catalogo::id] == $hash){
                    return $value;
                }
            }
        }
        kernel::log()->add_error(new \Exception("No existe el curso identificado con el siguiente hash: '{$hash}'"));
    }

    function decodificar_curso($hash, $propuesta_curso) {
        $datos = $this->modelo()->info__actividades_propuesta_curso_por_persona($propuesta_curso);
        if(!empty($datos)) {
            foreach($datos as $value){
                if($value[catalogo::id] == $hash){
                    return $value;
                }
            }
        }
        kernel::log()->add_error(new \Exception("No existe la actividad '{$hash}' para el curso '{$propuesta_curso}'"));
    }

    function decodificar_comision($hash, $curso){
        $datos = $this->modelo()->info__comisiones_curso($curso);
        if(!empty($datos)) {
            foreach($datos as $value){
                if($value[catalogo::id] == $hash){
                    return $value;
                }
            }
        }
        return false;
    }

    function decodificar_inscripcion($hash, $curso = null)
    {
        $datos = $this->modelo()->info__inscripciones($curso);
        if(!empty($datos)) {
            foreach($datos as $value){
                if($value[catalogo::id] == $hash){
                    return $value;
                }
            }
        }
        return false;
    }

    function set_propuesta($propuesta){

        //Guardo en sesion la propuesta seleccionada
        kernel::sesion()->set('__propuesta', $propuesta);
        klog2('sesion __propuesta', $propuesta);
        $datos_plan = \toba::consulta_php('co_propuestas')->get_plan_y_plan_version_propuesta_cursos($propuesta);
        if(empty($datos_plan['plan']) || empty($datos_plan['plan_version'])) {
            throw new error_guarani('No hay un plan activo para el tipo de cursos seleccionado.');
        }

        //Guardo en sesion el plan y plan_version
        kernel::sesion()->set('__plan', $datos_plan['plan']);
        klog2('sesion __plan', $datos_plan['plan']);
        kernel::sesion()->set('__plan_version', $datos_plan['plan_version']);
        klog2('sesion __plan_version', $datos_plan['plan_version']);
    }

    function accion__actividades_curso(){

        $hubo_error = false;
        try{
            $propuesta_curso_hash = $this->validate_param('propuesta_curso', 'get', validador::TIPO_ALPHANUM);
            $propuesta_curso = $this->decodificar_propuesta_curso($propuesta_curso_hash);

            $this->add_var('propuesta_curso_hash', $propuesta_curso_hash);
            $this->set_propuesta($propuesta_curso['valor']);

            $operacion = kernel::ruteador()->get_id_operacion();
            $data['catalogo_id'] = catalogo::id;
            $data['url_elegir_actividad_curso'] = kernel::vinculador()->crear($operacion, 'elegir_actividad_curso');
            $data['propuesta_curso_hash'] = $propuesta_curso_hash;
            $data['actividades'] = $this->modelo()->info__actividades_propuesta_curso_por_persona($propuesta_curso['valor']);
            kernel::renderer()->add_to_ajax_response('hubo_error', $hubo_error);
        }
        catch(\Exception $e){
            $hubo_error = true;
            kernel::renderer()->add_to_ajax_response('hubo_error', $hubo_error);
            kernel::renderer()->add_to_ajax_response('mensaje_error', kernel::traductor()->trans('inscripcion_cursos.error_al_cargar_cursos'));
        }

        if(!$hubo_error) $this->render_template('cursos/actividades.twig', $data);
    }

    function accion__elegir_propuesta_curso(){
        $propuesta_curso_hash = $this->validate_param(0, 'get', validador::TIPO_ALPHANUM);
        $this->add_var('propuesta_curso_hash', $propuesta_curso_hash);
        $pagelet = $this->vista()->pagelet('info_curso');
        $pagelet->set_estado_info(pagelet_info_curso::INICIAL);
        kernel::renderer()->add($pagelet);
    }

    function accion__elegir_actividad_curso(){

        $propuesta_curso_hash = $this->validate_param(0, 'get', validador::TIPO_ALPHANUM);
        $curso_hash = $this->validate_param(1, 'get', validador::TIPO_ALPHANUM);

        $propuesta_curso = $this->decodificar_propuesta_curso($propuesta_curso_hash);
        $curso = $this->decodificar_curso($curso_hash, $propuesta_curso['valor']);

        $pagelet = $this->vista()->pagelet('info_curso');
        $pagelet->set_estado_info(pagelet_info_curso::CURSO_SELECCIONADO);

        $resultado_sq = $this->get_param("estado_tramite_pago", 'get', 'int');
        if (!empty($resultado_sq)) {

            //Borro la cache de inscripciones a cursada
            guarani::persona()->cursadas()->reset();

            switch ($resultado_sq) {
                case \cobro::ESTADO_TRAMITE_PAGO_OK_INSTANTANEO:
                    $nro_transaccion_sq = $this->get_param("nro_transaccion", 'get', 'int');
                    $nro_transaccion_guarani = $this->get_param("transaccion_guarani", 'get', 'int');
                    $pagelet->add_var_js('msj_contenido', kernel::traductor()->trans('inscripcion_cursos.msg_estado_tramite_pago_ok_instantaneo', array('%codigo_transaccion%' => $nro_transaccion_sq)));
                    $pagelet->add_var_js('msj_tipo', 'alert-success');
                    $pagelet->add_var_js('mostrar_comprobante', true);
                    $pagelet->add_var_js('nro_transaccion', $nro_transaccion_guarani);
                    break;
                case \cobro::ESTADO_TRAMITE_PAGO_OK_PENDIENTE:
                    $nro_transaccion_sq = $this->get_param("nro_transaccion", 'get', 'int');
                    $nro_transaccion_guarani = $this->get_param("transaccion_guarani", 'get', 'int');
                    $pagelet->add_var_js('msj_contenido', kernel::traductor()->trans('inscripcion_cursos.msg_estado_tramite_pago_ok_pendiente', array('%codigo_transaccion%' => $nro_transaccion_sq)));
                    $pagelet->add_var_js('msj_tipo', 'alert');
                    $pagelet->add_var_js('mostrar_comprobante', true);
                    $pagelet->add_var_js('nro_transaccion', $nro_transaccion_guarani);
                    break;
                case \cobro::ESTADO_TRAMITE_PAGO_CANCELO:
                    $pagelet->add_var_js('msj_contenido', kernel::traductor()->trans('inscripcion_cursos.msg_estado_tramite_pago_cancelo'));
                    $pagelet->add_var_js('msj_tipo', 'alert');
                    break;
                case \cobro::ESTADO_TRAMITE_PAGO_ERROR:
                    $pagelet->add_var_js('msj_contenido', kernel::traductor()->trans('inscripcion_cursos.msg_estado_tramite_pago_error'));
                    $pagelet->add_var_js('msj_tipo', 'alert-error');
                    break;
            }
        }

        $this->add_var('propuesta_curso_hash', $propuesta_curso_hash);
        $this->add_var('propuesta_curso', $propuesta_curso);
        $this->add_var('curso_hash', $curso_hash);
        $this->add_var('curso', $curso);

        kernel::renderer()->add($pagelet);

    }


    function accion__inscribir()
    {
        $curso  = $this->validate_param('curso', 'post', validador::TIPO_INT);
        $hash_comision = $this->validate_param('comision', 'post', validador::TIPO_ALPHANUM);
        $codigo_inscripcion_cerrada = $this->validate_param('codigo_inscripcion_cerrada', 'post', validador::TIPO_TEXTO, array(
            'allowempty' => true
        ));

        try {
            $datos_comision = $this->decodificar_comision($hash_comision, $curso);

            //Si la comision usa inscripcion cerrada
            if($datos_comision['inscripcion_cerrada'] == \comunes::si){
				inscripcion_cursada::validar_codigo_inscripcion_cerrada($datos_comision['inscripcion_cerrada_codigo'], $codigo_inscripcion_cerrada);
            }

            $resultado = $this->modelo()->evt__inscribir_curso($datos_comision['propuesta'], $datos_comision['plan'], $datos_comision['plan_version'], $datos_comision['comision']);
            kernel::renderer()->add_to_ajax_response('mensaje_inscripcion', $resultado['mensaje_inscripcion']);
            if(isset($resultado['url_sq_pagos'])) kernel::renderer()->add_to_ajax_response('url_sq_pagos', $resultado['url_sq_pagos']);
            kernel::renderer()->add_to_ajax_response('abrir_SQ_pagos', $resultado['abrir_SQ_pagos']);
            kernel::renderer()->add_to_ajax_response('hubo_error_SQ', $resultado['hubo_error_SQ']);
            $pagelet = $this->vista()->pagelet('info_curso');
            $pagelet->set_estado_info(pagelet_info_curso::CURSO_SELECCIONADO);

            $this->add_var('mensajes', $resultado['mensajes_controles']);
            $this->add_var('curso', $datos_comision);
            $this->add_var('comision', $hash_comision);
            $this->add_var('ignorar_orden_comision', $hash_comision);
            kernel::renderer()->add($this->vista()->pagelet('info_curso'));
        } catch (error_guarani $e) {
            // Se genera un nuevo csrf xq el anterior ya se consumió
            kernel::renderer()->add_csrf($this->generar_csrf());
            $this->finalizar_request_con_notificaciones(kernel::traductor()->trans('inscripcion_cursos.insc_curso_error'), $e->get_mensajes_usuario());
        }
    }

    function accion__generar_comprobante()
    {
        $curso = $this->validate_param('curso', 'get', validador::TIPO_INT, array('default' => null));
        $hash_inscripcion = $this->validate_param('inscripcion', 'get', validador::TIPO_ALPHANUM);
        $imprimir = $this->validate_param('i', 'get', validador::TIPO_INT, array(
            'allowempty' => true,
            'default' => 0
        ));

        $existe_inscripcion = true;

        $datos_inscripcion = $this->decodificar_inscripcion($hash_inscripcion, $curso);

        // Verifico si existe la inscripción, puede ser que se haya dado de baja desde Gestión
        if(empty($datos_inscripcion)){
            $existe_inscripcion = false;
        }
        else{
            $existe_inscripcion = $this->modelo()->info__existe_inscripcion($datos_inscripcion['inscripcion']);
        }

        // Si no existe la inscripción
        if(!$existe_inscripcion){
            // Limpio la cache de inscripcion a cursadas
            guarani::persona()->cursadas()->reset();
            // Lanzo una excepción para generar un error
            throw new \Exception("No existe comprobante");
        }

        $datos = $this->modelo()->info__comprobante($datos_inscripcion['inscripcion']);

        if ($imprimir == 1) {
            $this->generador_comp->set_tipo_generacion(generador_comprobantes_cursos::TIPO_GENERACION_STREAM);
        } else {
            $this->generador_comp->set_tipo_generacion(generador_comprobantes_cursos::TIPO_GENERACION_DESCARGA);
        }
        $this->generador_comp->generar_comp_alta($datos);
        $this->finalizar_request();
    }

    function accion__enviar_comprobante()
    {
        $dir_mail = kernel::persona()->get_mail();
        if (empty($dir_mail)) {
            $this->finalizar_request();
        }

        $curso = $this->validate_param('curso', 'get', validador::TIPO_INT, array('default' => null));
        $hash_inscripcion = $this->validate_param('inscripcion', 'get', validador::TIPO_ALPHANUM);

        $datos_inscripcion = $this->decodificar_inscripcion($hash_inscripcion, $curso);
        $datos = $this->modelo()->info__comprobante($datos_inscripcion['inscripcion']);

        $asunto = kernel::traductor()->trans('asunto_mail_alta_certificado_cursada', array(
            '%1%' => $datos['fecha_inscripcion'],
            '%2%' => $datos['actividad_nombre']
        ));
        $tpl = kernel::load_template('info_curso/mail_comprobante_alta.twig');
        $cuerpo = $tpl->render(array());
        $path_archivo = kernel::proyecto()->get_dir_temp().'/'.  uniqid().'.png';
        $this->generador_comp->set_tipo_generacion(generador_comprobantes_cursos::TIPO_GENERACION_ARCHIVO);
        $this->generador_comp->set_path_destino($path_archivo);
        $this->generador_comp->generar_comp_alta($datos);
        $mail = new mail($dir_mail, $asunto, $cuerpo);
        $mail->agregar_adjunto('comprobante.png', $path_archivo);
        $mail->set_html(true);

        $mail->enviar();
        //Una vez enviado el email elimino el comprobante.
        unlink($path_archivo);
        $this->render_ajax();
    }

    //***************************************************************
    //****************** Baja Cursos ********************************
    //***************************************************************

    function accion__baja()
    {
        $curso              =   $this->validate_param('curso', 'post', validador::TIPO_INT);
        $hash_inscripcion   =   $this->validate_param('inscripcion', 'post', validador::TIPO_ALPHANUM);
        $hash_comision      =   $this->validate_param('comision', 'post', validador::TIPO_ALPHANUM);
        $baja_inicial       =   $this->validate_param('baja_inicial', 'post', validador::TIPO_TEXTO);
        $modo_devolucion    =   $this->validate_param('modo_devolucion', 'post', validador::TIPO_TEXTO, array(
            'allowempty' => true
        ));
        
        try {
            $datos_inscripcion = $this->decodificar_inscripcion($hash_inscripcion, $curso);
            $datos_comision = $this->decodificar_comision($hash_comision, $curso);

            $existe_inscripcion = true;

            // Verifico si existe la inscripción, puede ser que se haya dado de baja desde Gestión
            if(empty($datos_inscripcion)){
                $existe_inscripcion = false;
            }
            else{
                $existe_inscripcion = $this->modelo()->info__existe_inscripcion($datos_inscripcion['inscripcion']);
            }

            // Si no existe la inscripción
            if(!$existe_inscripcion){
                // Limpio la cache de inscripcion a cursadas
                guarani::persona()->cursadas()->reset();
                // Lanzo una excepción para generar un error
                $e = new error_guarani("");
                $msg_error = kernel::traductor()->trans('inscripcion_cursos.error_no_existe_inscripcion');
                $e->add_mensaje_usuario(guarani::crear_mensaje(guarani::control_error, $msg_error));
                throw $e;
            }
            
            $ras = \toba::consulta_php('co_responsables_academicas')->get_propuestas_ras($datos_inscripcion['propuesta']);
            $sq_usa_sanaviron = parametro::get_valor('sq_usa_sanaviron', $ras);
            //valido el modo de devolución del pago
            if(($sq_usa_sanaviron == comunes::si) && ($datos_inscripcion['comision_cobrable'] == comunes::si)) $this->validar_modo_devolucion_pago($modo_devolucion);
            $resultado = $this->modelo()->evt__eliminar_inscripcion($datos_comision['propuesta'], $datos_comision['plan'], $datos_comision['plan_version'], $datos_comision['comision'], $datos_inscripcion['inscripcion'], $datos_inscripcion['nro_transaccion'], $modo_devolucion);
            $this->guardar_id_cert_baja($hash_inscripcion, $resultado[2]);
            $href = kernel::vinculador()->crear(kernel::ruteador()->get_id_operacion(), 'comprobante_baja', array('inscripcion' => $hash_inscripcion));
            $link = kernel::traductor()->trans('link_baja_cursada', array('%1%' => $href));
            kernel::renderer()->add_to_ajax_response('mensaje_cert_baja', $resultado[0]." ".$link);
            $pagelet = $this->vista()->pagelet('info_curso');
            //Si es baja inicial selecciono estado 'INICIAL'
            if ($baja_inicial == \comunes::si) {
                kernel::sesion()->set('__propuesta', $datos_inscripcion['propuesta']);
                $pagelet->set_estado_info(pagelet_info_curso::INICIAL);
            }//Si NO es baja inicial selecciono estado 'CURSO_SELECCIONADO'
            elseif($baja_inicial == \comunes::no) {
                $pagelet->set_estado_info(pagelet_info_curso::CURSO_SELECCIONADO);
                $this->add_var('curso', $datos_comision);
            }
            $this->add_var('mensajes', $resultado['advertencias']);
            kernel::renderer()->add($pagelet);
        } catch (error_guarani $e){
            kernel::renderer()->add_csrf($this->generar_csrf());
            $this->finalizar_request_con_notificaciones(kernel::traductor()->trans('inscripcion_cursos.baja_insc_curso_error'), $e->get_mensajes_usuario());
        }
    }

    protected function validar_modo_devolucion_pago($modo_devolucion){
        if (!in_array($modo_devolucion, cobro::get_modos_devolucion_suscripcion_novedad())) {
            $e = new error_guarani('');
            $e->add_mensaje_usuario(guarani::crear_mensaje(guarani::control_error, kernel::traductor()->trans('inscripcion_cursos.modo_devolucion_pago_invalido')));
            throw $e;
        }
    }

    function accion__existe_inscripcion() {

        $curso              =   $this->validate_param('curso', 'get', validador::TIPO_INT);
        $hash_inscripcion   =   $this->validate_param('inscripcion', 'get', validador::TIPO_ALPHANUM);
        $hubo_error = false;
        $msj_error = '';
        $existe_inscripcion = true;

        try {
            $datos_inscripcion = $this->decodificar_inscripcion($hash_inscripcion, $curso);

            // Verifico si existe la inscripción, puede ser que se haya dado de baja desde Gestión
            if(empty($datos_inscripcion)){
                $existe_inscripcion = false;
            }
            else{
                $existe_inscripcion = $this->modelo()->info__existe_inscripcion($datos_inscripcion['inscripcion']);
            }

            // Si no existe la inscripción limpio la cache de inscripcion a cursadas
            if(!$existe_inscripcion) guarani::persona()->cursadas()->reset();

            kernel::renderer()->add_to_ajax_response("existe_inscripcion", $existe_inscripcion);
            kernel::renderer()->add_to_ajax_response("hubo_error", $hubo_error);
        } catch (\Exception $e){
            $hubo_error = true;
            $msj_error = kernel::traductor()->trans('inscripcion_cursos.error_averiguar_existe_inscripcion');
            kernel::log()->add_error($e);
            kernel::renderer()->add_to_ajax_response("hubo_error", $hubo_error);
            kernel::renderer()->add_to_ajax_response("msj_error", $msj_error);
        }
    }

    function accion__es_comision_cobrable() {

        $curso              =   $this->validate_param('curso', 'get', validador::TIPO_INT);
        $hash_inscripcion   =   $this->validate_param('inscripcion', 'get', validador::TIPO_ALPHANUM);
        $hubo_error = false;
        $msj_error = '';

        try {
            $datos_inscripcion = $this->decodificar_inscripcion($hash_inscripcion, $curso);
            $ras = \toba::consulta_php('co_responsables_academicas')->get_propuestas_ras($datos_inscripcion['propuesta']);
            $sq_usa_sanaviron = parametro::get_valor('sq_usa_sanaviron', $ras);
            $comision_cobrable = (($sq_usa_sanaviron == \comunes::si) && ($datos_inscripcion['comision_cobrable'] == \comunes::si));
            kernel::renderer()->add_to_ajax_response("comision_cobrable", $comision_cobrable);
            kernel::renderer()->add_to_ajax_response("hubo_error", $hubo_error);
        } catch (\Exception $e){
            $hubo_error = true;
            $msj_error = kernel::traductor()->trans('inscripcion_cursos.error_averiguar_comisión_cobrable');
            kernel::log()->add_error($e);
            kernel::renderer()->add_to_ajax_response("hubo_error", $hubo_error);
            kernel::renderer()->add_to_ajax_response("msj_error", $msj_error);
        }
    }

    function accion__obtener_forma_pago() {

        $curso              =   $this->validate_param('curso', 'get', validador::TIPO_INT);
        $hash_inscripcion   =   $this->validate_param('inscripcion', 'get', validador::TIPO_ALPHANUM);
        $hubo_error = false;
        $msj_error = '';

        try {
            $datos_inscripcion = $this->decodificar_inscripcion($hash_inscripcion, $curso);
            $estado_consumo_externo = cobro::get_estado_consumo_externo(cobro::TIPO_CONSUMO_SUSCRIPCION, $datos_inscripcion['nro_transaccion']);
            kernel::renderer()->add_to_ajax_response("estado", $estado_consumo_externo['estado']);
            kernel::renderer()->add_to_ajax_response("opciones_devolucion", $estado_consumo_externo['opciones_devolucion']);
            kernel::renderer()->add_to_ajax_response("select_opciones_devolucion", cobro::get_select_devolucion_suscripcion_novedad($estado_consumo_externo['opciones_devolucion']));
            kernel::renderer()->add_to_ajax_response("hubo_error", $hubo_error);
        } catch (\Exception $e){
            $hubo_error = true;
            $msj_error = kernel::traductor()->trans('inscripcion_cursos.error_comunicacion_sq');
            kernel::log()->add_error($e);
            kernel::renderer()->add_to_ajax_response("hubo_error", $hubo_error);
            kernel::renderer()->add_to_ajax_response("msj_error", $msj_error);
        }
    }

    function accion__comprobante_baja()
    {
        $hash_inscripcion = $this->validate_param('inscripcion', 'get', validador::TIPO_ALPHANUM);
        $inscripcion = $this->get_opcion_enviada_cert_baja($hash_inscripcion);
        $datos = $this->modelo()->info__comprobante_baja($inscripcion);
        $this->generador_comp->set_tipo_generacion(generador_comprobantes_cursos::TIPO_GENERACION_DESCARGA);
        $this->generador_comp->generar_comp_baja($datos);
        $this->finalizar_request();
    }

    //***************************************************************
    //****************** Fin Baja Cursos ****************************
    //***************************************************************

    //Verifica la solicitud de consumo externo
    function accion__verificar_sol_cons_ext(){

        if(kernel::request()->isPost()){
            try{
                $token_sq = $this->validate_param('token', 'post', validador::TIPO_TEXTO);

                $resultado_sq = cobro::actualizar_solicitud_consumo_externo($token_sq);
                $nuevo_token_sq = $resultado_sq['token'];

                if($token_sq != $nuevo_token_sq){//Si el token expiró lo reemplazo

                    \guarani::act('act_inscripcion_cursadas')->reemplazar_SQ_token(
                        $token_sq,
                        $nuevo_token_sq
                    );

                }

                $url_consumo_SQ_pagos = cobro::get_url_consumo_SQ_pagos($nuevo_token_sq);
                kernel::renderer()->add_to_ajax_response("url_consumo_SQ_pagos", $url_consumo_SQ_pagos);
            }
            catch(\Exception $e){
                klog2("Error PUT /solicitudes-consumos-externos", $e->getMessage());
                kernel::renderer()->add_to_ajax_response("error_pagar", true);
                kernel::renderer()->add_to_ajax_response("msj_error_pagar", kernel::traductor()->trans("inscripcion_cursos.error_pagar_inscripcion"));
            }
        }

    }

    protected function guardar_id_cert_baja($key, $value)
    {
        kernel::sesion()->set("id_cert_baja_$key", $value);
        $mensaje = kernel::traductor()->trans('inscripcion_cursos.mensaje_baja_comprobante', array(
            '%1%' => kernel::vinculador()->crear(kernel::ruteador()->get_id_operacion(), 'comprobante_baja', array(
                'baja' => $key
            ))
        ));
        kernel::renderer()->add_to_ajax_response('mensaje_cert_baja', $mensaje);
    }

    function get_opcion_enviada_cert_baja($url_inscripcion)
    {
        $clave = "id_cert_baja_$url_inscripcion";
        if (! kernel::sesion()->esta_seteada($clave)) {
            throw new error_guarani_seguridad('el id de baja no existe');
        }
        return kernel::sesion()->get($clave);
    }

    function accion__info_notificaciones()
    {
        $data = guarani::persona()->cursadas()->info__asignaciones();
        $this->render_raw_json($data);
    }

}