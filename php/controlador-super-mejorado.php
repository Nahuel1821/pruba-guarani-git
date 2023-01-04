<?php
namespace ffyb\operaciones\inscripcion_cursos;

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

class controlador extends \siu\operaciones\inscripcion_cursos\controlador
{
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
                $this->validar_codigo_inscripcion_cerrada($datos_comision['inscripcion_cerrada_codigo'], $codigo_inscripcion_cerrada);
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
            // Se genera un nuevo csrf xq el anterior ya se consumi�
            kernel::renderer()->add_csrf($this->generar_csrf());
            $this->finalizar_request_con_notificaciones(kernel::traductor()->trans('inscripcion_cursos.insc_curso_error'), $e->get_mensajes_usuario());
        }
    }

    protected function validar_codigo_inscripcion_cerrada($codigo_inscripcion_esperado, $codigo_inscripcion_recibido){

        $msg_error = "";

        //Si es vacio
        if(!empty($codigo_inscripcion_recibido)){

            //Si contiene la cantidad de caracteres esperados
            if(strlen($codigo_inscripcion_recibido) == \comision::CODIGO_INSCRIPCION_CANT_DIGITOS){

                //Si cumple con el formato
                if(preg_match('/'.\comision::CODIGO_INSCRIPCION_REGEXP.'/', $codigo_inscripcion_recibido)){

                    //Si es correcto
                    if(strcasecmp($codigo_inscripcion_recibido, $codigo_inscripcion_esperado) == 0){
                        return true;
                    }
                    else{//Si es incorrecto
                        $msg_error = kernel::traductor()->trans('inscripcion_cursos.codigo_inscripcion_incorrecto');
                    }
                }
                else{//Si no cumple con el formato
                    $msg_error = kernel::traductor()->trans('inscripcion_cursos.codigo_inscripcion_solo_letras_numeros');
                }

            }
            else{//Si no contiene la cantidad de caracteres esperados
                $msg_error = kernel::traductor()->trans('inscripcion_cursos.codigo_inscripcion_x_caracteres', array(
                    '%cant%' => \comision::CODIGO_INSCRIPCION_CANT_DIGITOS
                ));
            }
        }
        else{//Si no es vacio
            $msg_error = kernel::traductor()->trans('inscripcion_cursos.codigo_inscripcion_vacio');
        }

        $e = new error_guarani("");
        $e->add_mensaje_usuario(guarani::crear_mensaje(guarani::control_error, $msg_error));
        throw $e;
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
        $modo_devolucion    =   \cobro::SUSCRIPCIONES_NOVEDADES_MODO_DEVOLUCION_AGENTE_COBRANZA;;
        
        try {
            $datos_inscripcion = $this->decodificar_inscripcion($hash_inscripcion, $curso);
            $datos_comision = $this->decodificar_comision($hash_comision, $curso);

            $existe_inscripcion = true;

            // Verifico si existe la inscripci, puede ser que se haya dado de baja desde Gesti
            if(empty($datos_inscripcion)){
                $existe_inscripcion = false;
            }
            else{
                $existe_inscripcion = $this->modelo()->info__existe_inscripcion($datos_inscripcion['inscripcion']);
            }

            // Si no existe la inscripci
            if(!$existe_inscripcion){
                // Limpio la cache de inscripcion a cursadas
                guarani::persona()->cursadas()->reset();
                // Lanzo una excepci para generar un error
                $e = new error_guarani("");
                $msg_error = kernel::traductor()->trans('inscripcion_cursos.error_no_existe_inscripcion');
                $e->add_mensaje_usuario(guarani::crear_mensaje(guarani::control_error, $msg_error));
                throw $e;
            }
            
            $ras = \toba::consulta_php('co_responsables_academicas')->get_propuestas_ras($datos_inscripcion['propuesta']);
            $sq_usa_sanaviron = parametro::get_valor('sq_usa_sanaviron', $ras);
            //valido el modo de devoluci del pago
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

    function accion__obtener_forma_pago() {

        $curso              =   $this->validate_param('curso', 'get', validador::TIPO_INT);
        $hash_inscripcion   =   $this->validate_param('inscripcion', 'get', validador::TIPO_ALPHANUM);
        $hubo_error = false;
        $msj_error = '';

        try {
            $datos_inscripcion = $this->decodificar_inscripcion($hash_inscripcion, $curso);
            $estado_consumo_externo = cobro::get_estado_consumo_externo(cobro::TIPO_CONSUMO_SUSCRIPCION, $datos_inscripcion['nro_transaccion']);
            kernel::renderer()->add_to_ajax_response("estado", $estado_consumo_externo['estado']);
            kernel::renderer()->add_to_ajax_response("forma_pago", $estado_consumo_externo['forma_pago']);
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
}